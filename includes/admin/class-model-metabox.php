<?php
/**
 * TMW SEO Engine — Model Metabox renderer + save handler + AJAX handler.
 *
 * Extracted from class-model-helper.php as the seventh concrete step of
 * the god-class decomposition. This is the audit's biggest single named
 * target: render_metabox alone was 945 lines (cyclomatic complexity
 * roughly proportional to the page's many conditional render branches).
 *
 * Owns the model edit-screen "Model Research" surface:
 *   - register()          — add_meta_boxes target.
 *   - render($post)       — the 945-line metabox renderer (relocated intact,
 *                            internal decomposition is a follow-up).
 *   - render_candidate_review_section() — proposed-data review panel.
 *   - classify_external_candidate() + platform_slug_to_vl_type() — helpers.
 *   - save($post_id, $post) — save_post_model handler.
 *   - ajax_save_model_research() — wp_ajax_tmwseo_save_model_research handler.
 *   - enqueue_editor_assets() — block-editor JS/CSS for the metabox JS.
 *
 * Cross-class references back to ModelHelper:
 *   - All META_* constants (shared with ModelHelper's column renderers).
 *   - Helper methods: status_label, status_css_class, status_inline_style,
 *     field_text, field_textarea, field_number, sanitize_url_list,
 *     read_audit_bounds, audit_phase_label. These remain in ModelHelper
 *     because its column / merge / pipeline code also calls them.
 *     The 7 previously-private helpers were promoted to public as part
 *     of this extraction so the new class can call them.
 *
 * Hook surface (registered from ModelHelper::init()):
 *   add_action('add_meta_boxes', [ModelMetabox::class, 'register']);
 *   add_action('save_post_model', [ModelMetabox::class, 'save'], 10, 2);
 *   add_action('enqueue_block_editor_assets', [ModelMetabox::class, 'enqueue_editor_assets']);
 *   add_action('wp_ajax_tmwseo_save_model_research', [ModelMetabox::class, 'ajax_save_model_research']);
 *
 * Method renaming on extraction:
 *   ModelHelper::register_metabox → ModelMetabox::register
 *   ModelHelper::render_metabox   → ModelMetabox::render
 *   ModelHelper::save_metabox     → ModelMetabox::save
 *
 * @package TMWSEO\Engine\Admin
 * @since   5.x.0 (extraction PR)
 */
declare(strict_types=1);

namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Admin\ModelHelper;
use TMWSEO\Engine\Model\ModelResearchProvider;
use TMWSEO\Engine\Model\ModelContextAwareProvider;
use TMWSEO\Engine\Platform\PlatformRegistry;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ModelMetabox {

    public static function register(): void {
        add_meta_box(
            'tmwseo_model_research',
            __( 'Model Research', 'tmwseo' ),
            [ self::class, 'render' ],
            'model',
            'normal',
            'high'
        );
    }

    // ── Metabox: render ───────────────────────────────────────────────────

    public static function render_metabox( \WP_Post $post ): void {
        self::render( $post );
    }

    public static function render( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            echo '<p>' . esc_html__( 'You do not have permission to view this.', 'tmwseo' ) . '</p>';
            return;
        }

        wp_nonce_field( 'tmwseo_model_research_save_' . $post->ID, 'tmwseo_model_research_nonce' );

        $status       = (string) get_post_meta( $post->ID, ModelHelper::META_STATUS, true );
        $last_at      = (string) get_post_meta( $post->ID, ModelHelper::META_LAST_AT, true );
        $display_name = (string) get_post_meta( $post->ID, ModelHelper::META_DISPLAY_NAME, true );
        $aliases      = (string) get_post_meta( $post->ID, ModelHelper::META_ALIASES, true );
        $bio          = (string) get_post_meta( $post->ID, ModelHelper::META_BIO, true );
        $platforms    = (string) get_post_meta( $post->ID, ModelHelper::META_PLATFORMS, true );
        $social_raw   = (string) get_post_meta( $post->ID, ModelHelper::META_SOCIAL_URLS, true );
        $social_urls  = $social_raw !== '' ? implode( "\n", (array) json_decode( $social_raw, true ) ) : '';
        $country      = (string) get_post_meta( $post->ID, ModelHelper::META_COUNTRY, true );
        $language     = (string) get_post_meta( $post->ID, ModelHelper::META_LANGUAGE, true );
        $sources_raw  = (string) get_post_meta( $post->ID, ModelHelper::META_SOURCE_URLS, true );
        $source_urls  = $sources_raw !== '' ? implode( "\n", (array) json_decode( $sources_raw, true ) ) : '';
        $confidence   = (string) get_post_meta( $post->ID, ModelHelper::META_CONFIDENCE, true );
        $notes        = (string) get_post_meta( $post->ID, ModelHelper::META_NOTES, true );
        $seed_summary = (string) get_post_meta( $post->ID, ModelHelper::META_EDITOR_SEED_SUMMARY, true );
        $seed_tags    = (string) get_post_meta( $post->ID, ModelHelper::META_EDITOR_SEED_TAGS, true );
        $seed_platform_notes = (string) get_post_meta( $post->ID, ModelHelper::META_EDITOR_SEED_PLATFORM_NOTES, true );
        $seed_confirmed_facts = (string) get_post_meta( $post->ID, ModelHelper::META_EDITOR_SEED_CONFIRMED_FACTS, true );
        $seed_avoid_claims = (string) get_post_meta( $post->ID, ModelHelper::META_EDITOR_SEED_AVOID_CLAIMS, true );
        $seed_tone_hint = (string) get_post_meta( $post->ID, ModelHelper::META_EDITOR_SEED_TONE_HINT, true );
        // Bio evidence fields
        $bio_summary       = (string) get_post_meta( $post->ID, ModelHelper::META_BIO_SUMMARY, true );
        $bio_source_type   = (string) get_post_meta( $post->ID, ModelHelper::META_BIO_SOURCE_TYPE, true );
        $bio_review_status = (string) get_post_meta( $post->ID, ModelHelper::META_BIO_REVIEW_STATUS, true );
        $bio_reviewed_at   = (string) get_post_meta( $post->ID, ModelHelper::META_BIO_REVIEWED_AT, true );
        $bio_source_url    = (string) get_post_meta( $post->ID, ModelHelper::META_BIO_SOURCE_URL, true );
        $bio_source_label  = (string) get_post_meta( $post->ID, ModelHelper::META_BIO_SOURCE_LABEL, true );
        $bio_source_facts  = (string) get_post_meta( $post->ID, ModelHelper::META_BIO_SOURCE_FACTS, true );
        $proposed_raw = (string) get_post_meta( $post->ID, ModelHelper::META_PROPOSED, true );
        $proposed     = $proposed_raw !== '' ? json_decode( $proposed_raw, true ) : null;

        $status_label = ModelHelper::status_label( $status );

        // ── Status banner ─────────────────────────────────────────────────
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">';
        echo '<span><strong>' . esc_html__( 'Research Status:', 'tmwseo' ) . '</strong> ';
        echo '<span class="' . esc_attr( ModelHelper::status_css_class( $status ) ) . '" style="' . esc_attr( ModelHelper::status_inline_style( $status ) ) . '">';
        echo esc_html( $status_label );
        echo '</span></span>';

        if ( $last_at !== '' ) {
            echo '<span style="color:#666;font-size:12px;">';
            echo esc_html__( 'Last researched:', 'tmwseo' ) . ' ' . esc_html( $last_at );
            echo '</span>';
        }

        // ── "Research Now" button — JS-powered direct AJAX (no loopback) ───
        // The button fires tmwseo_trigger_research directly from the browser.
        // This avoids the server-to-server loopback that Cloudflare/LiteSpeed block.
        $trigger_nonce = wp_create_nonce( 'tmwseo_trigger_research_' . $post->ID );
        $fallback_url  = wp_nonce_url(
            admin_url( 'admin-post.php?action=tmwseo_run_model_research&post_id=' . $post->ID ),
            'tmwseo_run_research_' . $post->ID
        );
        echo '<button type="button" id="tmwseo-research-btn" class="button" style="margin-left:auto;">';
        echo esc_html__( 'Research Now', 'tmwseo' );
        echo '</button>';
        // Full Audit: exhaustive search across all registered platforms
        $audit_nonce = wp_create_nonce( 'tmwseo_full_audit_' . $post->ID );
        echo '<button type="button" id="tmwseo-audit-btn" class="button" '
             . 'style="margin-left:6px;" '
             . 'title="' . esc_attr__( 'Exhaustively search all registered platforms. Slower but thorough — use for first-time research or when Research Now misses platforms.', 'tmwseo' ) . '">'
             . '🔍 ' . esc_html__( 'Full Audit', 'tmwseo' ) . '</button>';
        echo '</div>';

        // Candidate-only import framework. This does not fetch or persist data.
        ModelHelper::render_public_profile_import( $post->ID );

        // ── Research Now: progress bar + synchronous XHR ──────────────────────
        // HONESTY RULE: the progress bar and animation only appear after the button
        // is clicked and the XHR is confirmed in-flight. If the page loads with
        // status=queued (stale from a previous broken run) we show a warning, NOT
        // a fake animated bar.
        $ajax_url      = esc_url( admin_url( 'admin-ajax.php' ) );
        $poll_nonce    = wp_create_nonce( 'tmwseo_status_poll_' . $post->ID );
        $post_id_js    = (int) $post->ID;
        $stale_queued  = ( $status === 'queued' );  // stuck from a previous run
        $fallback_url  = esc_url( $fallback_url );  // no-JS admin-post fallback

        // ── Stale-queued notice (shown only when page loads with stuck status) ──
        if ( $stale_queued ) {
            echo '<div id="tmwseo-stale-notice" style="margin:0 0 12px;padding:10px 14px;border:1px solid #f5c6cb;border-radius:4px;background:#fff5f5;">';
            echo '<strong style="color:#721c24;">⚠ ' . esc_html__( 'Previous research did not complete.', 'tmwseo' ) . '</strong> ';
            echo esc_html__( 'The pipeline was queued but never finished (the server process likely timed out or was killed). Click Research Now to run it again.', 'tmwseo' );
            echo '</div>';
        }

        // ── Progress bar widget — hidden until button is clicked ──────────────
        echo '<div id="tmwseo-poll-box" style="display:none;margin:0 0 14px;border:1px solid #aed6f1;border-radius:4px;background:#ebf5fb;padding:10px 14px;">';
        echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
        echo '<span style="font-size:18px;">🔬</span>';
        echo '<strong style="color:#1a5276;">' . esc_html__( 'Research in progress…', 'tmwseo' ) . '</strong>';
        echo '<span id="tmwseo-poll-status-text" style="font-size:12px;color:#555;margin-left:4px;"></span>';
        echo '<span id="tmwseo-poll-eta" style="font-size:11px;color:#888;margin-left:auto;"></span>';
        echo '</div>';
        echo '<div style="background:#d6eaf8;border-radius:20px;height:14px;overflow:hidden;position:relative;">';
        echo '<div id="tmwseo-poll-bar" style="height:100%;width:0%;border-radius:20px;background:linear-gradient(90deg,#2980b9,#27ae60);transition:width 0.8s ease;"></div>';
        echo '<div style="position:absolute;top:0;left:0;right:0;bottom:0;background:linear-gradient(90deg,transparent 0%,rgba(255,255,255,0.35) 50%,transparent 100%);animation:tmwseo-shimmer 1.6s infinite;"></div>';
        echo '</div>';
        echo '<div style="margin-top:5px;font-size:11px;color:#555;">';
        echo esc_html__( 'Searching platforms, extracting profiles, merging results. Page updates automatically when done.', 'tmwseo' );
        echo '</div>';
        echo '<div id="tmwseo-poll-error" style="display:none;margin-top:8px;padding:6px 10px;background:#fff5f5;border:1px solid #f5c6cb;border-radius:3px;font-size:12px;color:#721c24;"></div>';
        echo '</div>';
        echo '<style>@keyframes tmwseo-shimmer{0%{transform:translateX(-100%)}100%{transform:translateX(200%)}}</style>';

        echo '<script>';
        echo '(function(){';
        echo 'var AJAX="'      . $ajax_url . '";';
        echo 'var POLL_N="'    . esc_js( $poll_nonce ) . '";';
        echo 'var TRIG_N="'    . esc_js( $trigger_nonce ) . '";';
        echo 'var PID='        . $post_id_js . ';';
        echo 'var FALLBACK="'  . esc_js( $fallback_url ) . '";';
        echo 'var EXPECTED=90000;';
        echo 'var bar=document.getElementById("tmwseo-poll-bar");';
        echo 'var sTxt=document.getElementById("tmwseo-poll-status-text");';
        echo 'var etaTxt=document.getElementById("tmwseo-poll-eta");';
        echo 'var box=document.getElementById("tmwseo-poll-box");';
        echo 'var errBox=document.getElementById("tmwseo-poll-error");';
        echo 'var staleNotice=document.getElementById("tmwseo-stale-notice");';
        echo 'var btn=document.getElementById("tmwseo-research-btn");';
        echo 'var phases=["Querying search engines…","Probing platform profiles…","Extracting usernames…","Merging results…","Finalising data…"];';
        echo 'var barTimer=null;';

        echo 'function setBar(p){if(bar)bar.style.width=Math.min(p,100)+"%";}';
        echo 'function showError(msg){';
        echo '  if(errBox){errBox.textContent=msg;errBox.style.display="block";errBox.style.background="#fff5f5";errBox.style.borderColor="#f5c6cb";errBox.style.color="#721c24";}';
        echo '  if(sTxt)sTxt.textContent="Failed.";';
        echo '  if(etaTxt)etaTxt.textContent="";';
        echo '  setBar(0);';
        echo '  if(btn){btn.disabled=false;btn.textContent="Research Now";delete btn.dataset.running;}';
        echo '}';
        // v5.4.0 — showInfo() paints a blue informational notice WITHOUT
        // changing the main status text to "Failed.". Used for the
        // "browser stopped waiting but the worker is still alive" case,
        // which is NOT a failure.
        echo 'function showInfo(msg){';
        echo '  if(errBox){errBox.textContent=msg;errBox.style.display="block";errBox.style.background="#e7f1fb";errBox.style.borderColor="#aed6f1";errBox.style.color="#1a5276";}';
        echo '  if(etaTxt)etaTxt.textContent="";';
        echo '}';
        // v5.4.0 — showStale() paints an orange/warning notice for the
        // case where the worker is detected as stalled (no checkpoint
        // advance). Distinct from both "failed" and "still running".
        echo 'function showStale(msg){';
        echo '  if(errBox){errBox.textContent=msg;errBox.style.display="block";errBox.style.background="#fff5e6";errBox.style.borderColor="#f0c37a";errBox.style.color="#7a4f00";}';
        echo '  if(sTxt)sTxt.textContent="Stalled.";';
        echo '  if(etaTxt)etaTxt.textContent="";';
        echo '  setBar(0);';
        echo '  if(btn){btn.disabled=false;btn.textContent="Research Now";delete btn.dataset.running;}';
        echo '}';
        echo 'function silentReload(){';
        echo '  setBar(100);';
        echo '  if(sTxt)sTxt.textContent="Done! Loading results…";';
        echo '  if(etaTxt)etaTxt.textContent="";';
        echo '  window.onbeforeunload=null;';
        echo '  if(window.wp&&wp.data){try{wp.data.dispatch("core/editor").resetPost();}catch(e){}}';
        echo '  setTimeout(function(){location.replace(location.href.replace(/[?&]tmwseo_research_queued=1/,""));},600);';
        echo '}';
        echo 'function startBarAnimation(){';
        echo '  var startMs=Date.now();';
        echo '  if(sTxt)sTxt.textContent=phases[0];';
        echo '  barTimer=setInterval(function(){';
        echo '    var el=Date.now()-startMs;';
        echo '    var pct=el<EXPECTED?Math.round((el/EXPECTED)*90):90+Math.min(8,Math.round(((el-EXPECTED)/60000)*4));';
        echo '    setBar(pct);';
        echo '    if(sTxt)sTxt.textContent=phases[Math.min(Math.floor(el/18000),phases.length-1)];';
        echo '    if(etaTxt)etaTxt.textContent=Math.round(el/1000)+"s elapsed";';
        echo '  },1000);';
        echo '}';

        // ── Button click: show bar, fire XHR, wait for response ──────────────
        echo 'if(btn){btn.addEventListener("click",function(){';
        echo '  if(btn.dataset.running)return;';
        echo '  btn.dataset.running="1";btn.disabled=true;btn.textContent="Running…";';
        // Hide stale notice, show progress bar
        echo '  if(staleNotice)staleNotice.style.display="none";';
        echo '  if(box)box.style.display="block";';
        echo '  if(errBox)errBox.style.display="none";';
        echo '  startBarAnimation();';
        // XHR — 290s timeout (safely under Cloudflare's 300s proxy limit)
        echo '  var x=new XMLHttpRequest();';
        echo '  x.timeout=290000;';
        echo '  x.open("POST",AJAX,true);';
        echo '  x.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        // SUCCESS: pipeline ran, reload to show results
        echo '  x.onload=function(){';
        echo '    clearInterval(barTimer);';
        echo '    try{';
        echo '      var d=JSON.parse(x.responseText);';
        echo '      if(d.success&&d.data&&d.data.status&&d.data.status!=="queued"){silentReload();return;}';
        // Error or still-queued returned: show honest message
        echo '      var msg=d.data&&d.data.message?d.data.message:"Research failed — check server logs for [TMW-RESEARCH] entries.";';
        echo '      showError(msg+" Use the fallback: <a href=\\""+FALLBACK+"\\">Run synchronously (no-JS path)</a>");';
        echo '    }catch(e){silentReload();}';   // unparseable but 200 — reload anyway
        echo '  };';
        // TIMEOUT: Cloudflare cut the connection — fall back to polling
        echo '  x.ontimeout=function(){';
        echo '    clearInterval(barTimer);';
        echo '    if(sTxt)sTxt.textContent="Request timed out — checking status…";';
        // Poll for up to 5 more minutes to catch a Cloudflare-cut-but-still-running case
        echo '    var attempts=0;var pt=setInterval(function(){';
        echo '      attempts++;if(attempts>75){clearInterval(pt);';
        echo '      showError("Timed out after 5 minutes. Add a Cloudflare Page Rule to disable proxying for /wp-admin/admin-ajax.php and try again.");return;}';
        echo '      var p=new XMLHttpRequest();p.open("POST",AJAX,true);';
        echo '      p.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo '      p.onreadystatechange=function(){if(p.readyState!==4)return;';
        echo '        try{var d=JSON.parse(p.responseText);';
        echo '          if(d.success&&d.data&&d.data.status&&d.data.status!=="queued"){clearInterval(pt);silentReload();}';
        echo '        }catch(e){}};';
        echo '      p.send("action=tmwseo_research_status_poll&post_id="+PID+"&nonce="+POLL_N);';
        echo '    },4000);';
        echo '  };';
        // NETWORK ERROR: server unreachable
        echo '  x.onerror=function(){clearInterval(barTimer);showError("Network error — the server did not respond.");};';
        echo '  x.send("action=tmwseo_trigger_research&post_id="+PID+"&nonce="+TRIG_N);';
        echo '});}';

        // ── Full Audit button click handler ───────────────────────────────────
        // v5.3.0: enqueue a durable background job (returns instantly) and
        // poll status until the worker picks it up and finishes. This means
        // the audit no longer dies if the browser closes or the proxy times
        // out — the worker keeps writing per-phase checkpoints to post meta.
        //
        // Endpoint wiring:
        //   primary:  tmwseo_enqueue_full_audit  → durable background job
        //   fallback: tmwseo_run_full_audit      → synchronous in-request
        //                                          (auto-triggered server-side
        //                                          by ajax_enqueue_full_audit
        //                                          when the JobWorker class
        //                                          is unavailable).
        echo 'var auditBtn=document.getElementById("tmwseo-audit-btn");';
        echo 'var AUDIT_N="' . esc_js( $audit_nonce ) . '";';
        echo 'if(auditBtn){auditBtn.addEventListener("click",function(){';
        echo '  if(auditBtn.dataset.running)return;';
        echo '  auditBtn.dataset.running="1";auditBtn.disabled=true;auditBtn.textContent="Enqueuing full audit…";';
        echo '  if(box)box.style.display="block";';
        echo '  if(errBox)errBox.style.display="none";';
        echo '  if(staleNotice)staleNotice.style.display="none";';
        echo '  if(sTxt)sTxt.textContent="Full audit — enqueuing background job…";';
        echo '  startBarAnimation();';
        // STEP 1: enqueue the job (returns ~50ms with status:"queued").
        echo '  var parseJsonResponse=function(txt){try{return {ok:true,json:JSON.parse(txt||"")};}catch(e){return {ok:false,error:e};}};';
        echo '  var safeResponseSnippet=function(txt){var raw=(txt||"").toString().replace(/\s+/g," ").trim();if(!raw)return "(empty response)";return raw.substring(0,220);};';
        echo '  var ax=new XMLHttpRequest();ax.timeout=120000;';
        echo '  ax.open("POST",AJAX,true);';
        echo '  ax.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo '  ax.onload=function(){';
        echo '    var parsed=parseJsonResponse(ax.responseText);';
        echo '    if(!parsed.ok){clearInterval(barTimer);auditBtn.disabled=false;auditBtn.textContent="🔍 Full Audit";delete auditBtn.dataset.running;var snippet=safeResponseSnippet(ax.responseText);showError("Server returned invalid response: "+snippet);console.warn("[TMW-MODEL-AUDIT-AJAX] invalid response caught by JS",{post_id:PID,response_snippet:snippet});return;}';
        echo '    var d=parsed.json;';
        echo '      if(!d.success){clearInterval(barTimer);auditBtn.disabled=false;auditBtn.textContent="🔍 Full Audit";delete auditBtn.dataset.running;showError(d.data&&d.data.message?d.data.message:"Could not enqueue Full Audit.");return;}';
        // If the fallback path ran sync and returned a terminal status, just reload.
        echo '      var st=d.data&&d.data.status?d.data.status:"queued";';
        echo '      if(st==="researched"||st==="partial"||st==="error"){silentReload();return;}';
        // STEP 2: poll until the worker picks it up and reaches a terminal state.
        echo '      auditBtn.textContent="Running full audit…";';
        echo '      if(sTxt)sTxt.textContent="Background job running — phase: queued";';
        // v5.4.0 state machine:
        //   - is_terminal=true  → reload immediately (success path)
        //   - is_stale=true     → paint orange "stalled" notice, stop polling
        //   - neither           → keep polling; if we cross the watchdog,
        //                         show BLUE "still running in background"
        //                         (never conflate long-running with failure)
        echo '      var pollAttempts=0;';
        echo '      var watchdogPolls=225;';     // 225 × 4s = 900s = 15 min of polling
        echo '      var watchdogFired=false;';
        echo '      var pt=setInterval(function(){';
        echo '        pollAttempts++;';
        echo '        var p=new XMLHttpRequest();p.open("POST",AJAX,true);';
        echo '        p.setRequestHeader("Content-Type","application/x-www-form-urlencoded");';
        echo '        p.onreadystatechange=function(){if(p.readyState!==4)return;';
        echo '          try{var pd=JSON.parse(p.responseText);';
        echo '            if(!pd.success||!pd.data)return;';
        echo '            var d2=pd.data;';
        echo '            if(sTxt&&d2.phase_label&&!watchdogFired)sTxt.textContent="Phase: "+d2.phase_label;';
        // Terminal — reload and let the page re-render with real state.
        echo '            if(d2.is_terminal){clearInterval(pt);clearInterval(barTimer);';
        echo '              if(d2.status==="error"){';
        echo '                showError("Full Audit failed — see the metabox panel for details.");';
        echo '              }else{silentReload();}';
        echo '              return;';
        echo '            }';
        // Stalled — server detected no checkpoint advance for > threshold.
        echo '            if(d2.is_stale){clearInterval(pt);clearInterval(barTimer);';
        echo '              showStale("Full Audit appears stalled — no checkpoint advanced for "+(d2.stale_seconds||0)+"s. The page will now reload so you can see the partial results.");';
        echo '              setTimeout(function(){silentReload();},2500);';
        echo '              return;';
        echo '            }';
        // Not terminal, not stale — either still healthy or past browser watchdog.
        echo '            if(!watchdogFired&&pollAttempts>watchdogPolls){';
        echo '              watchdogFired=true;';
        echo '              showInfo("This audit is still running in the background. The page stopped live-watching after "+(watchdogPolls*4)+"s — you can close this tab and come back later; the worker keeps writing checkpoints.");';
        echo '              if(sTxt)sTxt.textContent="Phase: "+(d2.phase_label||"(in progress)")+" — background continues";';
        echo '            }';
        echo '          }catch(e){}';
        echo '        };';
        echo '        p.send("action=tmwseo_research_status_poll&post_id="+PID+"&nonce="+POLL_N);';
        echo '      },4000);';
        echo '  };';
        echo '  ax.ontimeout=function(){clearInterval(barTimer);showError("Enqueue request timed out — the worker may still pick it up. Refresh in 60s to check.");};';
        echo '  ax.onerror=function(){clearInterval(barTimer);showError("Network error.");auditBtn.disabled=false;auditBtn.textContent="🔍 Full Audit";delete auditBtn.dataset.running;};';
        echo '  ax.send("action=tmwseo_enqueue_full_audit&post_id="+PID+"&nonce="+AUDIT_N);';
        echo '});}';

        echo '})();';
        echo '</script>';

        // ── Provider notice if no provider is configured ───────────────────
        $providers = ModelResearchPipeline::get_providers();
        $only_stub = count( $providers ) === 1 && $providers[0] instanceof ModelResearchStub;
        if ( $only_stub ) {
            echo '<div class="notice notice-info inline" style="margin:0 0 12px;">';
            echo '<p>';
            echo '<strong>' . esc_html__( 'No research provider configured yet.', 'tmwseo' ) . '</strong> ';
            echo esc_html__(
                'You can still enter data manually below. To enable automatic research, register a provider via the tmwseo_research_providers filter.',
                'tmwseo'
            );
            echo '</p></div>';
        }

        // ── Phase 2 (v5.3.0): truthful audit status / proposed-data debug ───
        // Replaces the v5.2.0 "Researched but no proposed data was saved"
        // warning. The metabox now consults the durable phase tracker
        // (META_AUDIT_PHASE) and bounds blob (META_AUDIT_BOUNDS), so it can
        // tell the operator the truth about what happened — whether the run
        // is currently still running, was interrupted, completed in bounds,
        // or genuinely failed.
        $audit_phase  = (string) get_post_meta( $post->ID, ModelHelper::META_AUDIT_PHASE, true );
        $audit_bounds = ModelHelper::read_audit_bounds( $post->ID );

        if ( $status === 'running' || $audit_phase === 'serp_pass1' || $audit_phase === 'serp_pass2'
            || $audit_phase === 'probe' || $audit_phase === 'harvest' || $audit_phase === 'finalizing' ) {
            $human_phase = ModelHelper::audit_phase_label( $audit_phase );
            echo '<div class="notice notice-info inline" style="margin:0 0 12px;">';
            echo '<p><strong>[TMW-AUDIT]</strong> ';
            /* translators: %s: phase label, e.g. "SERP pass 1" */
            echo esc_html( sprintf( __( 'Full Audit is running — current phase: %s. The page will refresh automatically when results are ready.', 'tmwseo' ), $human_phase ) );
            echo '</p></div>';
        } elseif ( $status === 'partial' || $status === 'error' ) {
            $reason        = (string) ( $audit_bounds['reason']        ?? '' );
            $duration      = (int)    ( $audit_bounds['duration_ms']   ?? 0 );
            $stale_seconds = (int)    ( $audit_bounds['stale_seconds'] ?? 0 );
            $last_phase    = (string) ( $audit_bounds['last_known_phase'] ?? '' );
            $job_error     = (string) ( $audit_bounds['job_error']     ?? '' );
            $fatal_msg     = (string) ( $audit_bounds['fatal_message'] ?? '' );
            $fatal_file    = (string) ( $audit_bounds['fatal_file']    ?? '' );
            $fatal_line    = (int)    ( $audit_bounds['fatal_line']    ?? 0 );
            $probe_err     = (string) ( $audit_bounds['probe_error']   ?? '' );
            $probe_http    = (int)    ( $audit_bounds['probe_http']    ?? 0 );

            // Pick a human-readable reason banner based on the stall
            // category — never leave the operator staring at a bare
            // "worker_stalled" string with zero context.
            // v5.5.0: reason keys we can emit include
            //   worker_never_started / worker_stalled_mid_run
            //   worker_stalled (legacy v5.4.0)
            //   exception / php_fatal / json_encode_failed / round_trip_decode_failed
            //   worker_job_row_failed
            $reason_label_map = [
                'worker_never_started'   => __( 'The background worker never picked up the job. This usually means WP-Cron is not firing AND the loopback request to admin-ajax is blocked (firewall / mod_security / Cloudflare).', 'tmwseo' ),
                'worker_stalled_mid_run' => __( 'The background worker started but stopped advancing mid-run.', 'tmwseo' ),
                'worker_stalled'         => __( 'The background worker stopped advancing.', 'tmwseo' ),
                'worker_job_row_failed'  => __( 'The background job failed with an error.', 'tmwseo' ),
                'php_fatal'              => __( 'A PHP fatal error killed the worker process.', 'tmwseo' ),
                'exception'              => __( 'An exception was raised inside the audit pipeline.', 'tmwseo' ),
                'json_encode_failed'     => __( 'Encoding the audit result as JSON failed.', 'tmwseo' ),
                'round_trip_decode_failed' => __( 'The persisted audit blob failed round-trip JSON decode.', 'tmwseo' ),
            ];
            $reason_label = $reason_label_map[ $reason ] ?? $reason;

            $class = ( $status === 'error' ) ? 'notice-error' : 'notice-warning';
            echo '<div class="notice ' . esc_attr( $class ) . ' inline" style="margin:0 0 12px;">';
            echo '<p><strong>[TMW-AUDIT]</strong> ';
            if ( $status === 'error' ) {
                echo esc_html__( 'Full Audit did not complete.', 'tmwseo' );
            } else {
                echo esc_html__( 'Full Audit was interrupted before completion; partial results are available below.', 'tmwseo' );
            }
            if ( $reason_label !== '' ) {
                echo ' ' . esc_html( $reason_label );
            }
            echo '</p>';

            // Operator-visible diagnostics — only print what we have,
            // never fake-fill zeros.
            $rows = [];
            if ( $reason !== '' ) {
                $rows[] = [ __( 'Reason code',       'tmwseo' ), $reason ];
            }
            if ( $last_phase !== '' ) {
                $rows[] = [ __( 'Last known phase',  'tmwseo' ), ModelHelper::audit_phase_label( $last_phase ) ];
            }
            if ( $duration > 0 ) {
                $rows[] = [ __( 'Duration before stop (ms)', 'tmwseo' ), $duration ];
            }
            if ( $stale_seconds > 0 ) {
                $rows[] = [ __( 'Seconds without checkpoint', 'tmwseo' ), $stale_seconds ];
            }
            if ( $job_error !== '' ) {
                $rows[] = [ __( 'Worker error_message', 'tmwseo' ), $job_error ];
            }
            if ( $fatal_msg !== '' ) {
                $rows[] = [ __( 'PHP fatal', 'tmwseo' ), $fatal_msg . ( $fatal_file !== '' ? ' (' . $fatal_file . ':' . $fatal_line . ')' : '' ) ];
            }
            if ( $probe_err !== '' || $probe_http > 0 ) {
                $rows[] = [ __( 'Loopback probe', 'tmwseo' ), ( $probe_http > 0 ? 'HTTP ' . $probe_http : '' ) . ( $probe_err !== '' ? ' — ' . $probe_err : '' ) ];
            }

            if ( ! empty( $rows ) ) {
                echo '<table class="widefat striped" style="margin:4px 0 8px;border:none;">';
                foreach ( $rows as $r ) {
                    echo '<tr><th style="width:35%;font-weight:normal;color:#50575e;">' . esc_html( (string) $r[0] ) . '</th>';
                    echo '<td><code style="word-break:break-word;">' . esc_html( (string) $r[1] ) . '</code></td></tr>';
                }
                echo '</table>';
            }

            // Remediation hint tailored to the specific root cause.
            echo '<p style="margin:6px 0 0;font-size:12px;color:#555;">';
            if ( $reason === 'worker_never_started' ) {
                echo esc_html__( 'Remediation: enable Linux cron (e.g. */1 * * * * wp cron event run --due-now) OR whitelist loopback POSTs to admin-ajax.php in your firewall / mod_security. In the meantime you can click Full Audit again — the plugin will auto-detect a blocked loopback and run synchronously as a fallback.', 'tmwseo' );
            } elseif ( $reason === 'php_fatal' ) {
                echo esc_html__( 'Remediation: check the PHP error log for a full stack trace. Raise memory_limit if this is an OOM (out-of-memory) condition.', 'tmwseo' );
            } else {
                echo esc_html__( 'Click Full Audit to retry.', 'tmwseo' );
            }
            echo '</p>';
            echo '</div>';
        } elseif ( $status === 'researched' ) {
            if ( $proposed_raw === '' ) {
                // v5.3.0: this state should now only occur if the post was
                // marked researched manually (save_metabox edit) but no
                // proposed blob was ever produced. The honest message says
                // exactly that — no longer claims a timeout.
                echo '<div class="notice notice-info inline" style="margin:0 0 12px;">';
                echo '<p><strong>[TMW-RESEARCH]</strong> ';
                echo esc_html__( 'Status is "Researched" but no automated research blob is stored — fields were entered manually or applied directly. Click Research Now or Full Audit to populate proposed data.', 'tmwseo' );
                echo '</p></div>';
            } elseif ( $proposed === null ) {
                echo '<div class="notice notice-error inline" style="margin:0 0 12px;">';
                echo '<p><strong>[TMW-RESEARCH]</strong> ';
                /* translators: %d: byte length of raw proposed blob */
                echo esc_html( sprintf(
                    __( 'Proposed data blob exists (%d bytes) but could not be decoded as JSON — it may have been truncated. Click Research Now to re-run.', 'tmwseo' ),
                    strlen( $proposed_raw )
                ) );
                echo '</p></div>';
            } elseif ( is_array( $proposed ) && empty( $proposed['merged'] ) ) {
                $ps   = esc_html( (string) ( $proposed['pipeline_status'] ?? 'unknown' ) );
                $keys = esc_html( implode( ', ', array_keys( $proposed ) ) );
                $prs  = isset( $proposed['provider_results'] ) && is_array( $proposed['provider_results'] )
                    ? $proposed['provider_results'] : [];
                $pr_msgs = [];
                foreach ( $prs as $pname => $presult ) {
                    $pmsg = (string) ( $presult['message'] ?? $presult['error'] ?? '' );
                    if ( $pmsg !== '' ) {
                        $pr_msgs[] = esc_html( $pname . ': ' . $pmsg );
                    }
                }
                echo '<div class="notice notice-warning inline" style="margin:0 0 12px;">';
                echo '<p><strong>[TMW-RESEARCH]</strong> ';
                /* translators: %1$s pipeline status, %2$s top-level key list */
                echo esc_html( sprintf(
                    __( 'Research ran but merged data is empty. Pipeline status: %1$s. Top-level keys: %2$s.', 'tmwseo' ),
                    (string) ( $proposed['pipeline_status'] ?? 'unknown' ),
                    implode( ', ', array_keys( $proposed ) )
                ) );
                if ( ! empty( $pr_msgs ) ) {
                    echo ' ' . implode( ' | ', $pr_msgs );
                }
                echo ' ' . esc_html__( 'Click Research Now to re-run.', 'tmwseo' );
                echo '</p></div>';
            }
        }

        // ── Audit bounds panel — show actual coverage truthfully ─────────────
        // Renders only when an audit has run (bounds blob is non-empty).
        if ( ! empty( $audit_bounds ) && ( $status === 'researched' || $status === 'partial' ) ) {
            $platforms_in_registry = (int) ( $audit_bounds['platforms_in_registry'] ?? 0 );
            $platforms_checked     = (int) ( $audit_bounds['platforms_checked']     ?? 0 );
            $platforms_confirmed   = (int) ( $audit_bounds['platforms_confirmed']   ?? 0 );
            $probes_attempted      = (int) ( $audit_bounds['probes_attempted']      ?? 0 );
            $probes_accepted       = (int) ( $audit_bounds['probes_accepted']       ?? 0 );
            $queries_built         = (int) ( $audit_bounds['total_queries_built']   ?? 0 );
            $queries_succeeded     = (int) ( $audit_bounds['queries_succeeded']     ?? 0 );
            $duration_ms           = (int) ( $audit_bounds['duration_ms']           ?? 0 );

            echo '<details style="margin:0 0 12px;border:1px solid #c3c4c7;border-radius:4px;background:#fafafa;">';
            echo '<summary style="cursor:pointer;padding:8px 12px;font-weight:600;color:#1d2327;">';
            echo '⚙ ' . esc_html__( 'Full Audit bounds (what was actually attempted)', 'tmwseo' );
            echo '</summary>';
            echo '<table class="widefat striped" style="margin:0;border:none;">';
            $row = static function ( string $label, $value ) : void {
                echo '<tr><th style="width:55%;font-weight:normal;color:#50575e;">' . esc_html( $label ) . '</th>';
                echo '<td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
            };
            $row( __( 'Platforms in registry',           'tmwseo' ), $platforms_in_registry );
            $row( __( 'Platforms actually checked',      'tmwseo' ), $platforms_checked );
            $row( __( 'Platforms confirmed',             'tmwseo' ), $platforms_confirmed );
            $row( __( 'SERP queries built',              'tmwseo' ), $queries_built );
            $row( __( 'SERP queries succeeded',          'tmwseo' ), $queries_succeeded );
            $row( __( 'Direct probes attempted',         'tmwseo' ), $probes_attempted );
            $row( __( 'Direct probes accepted',          'tmwseo' ), $probes_accepted );
            $row( __( 'Duration (ms)',                   'tmwseo' ), $duration_ms );
            $row( __( 'Final phase',                     'tmwseo' ), ModelHelper::audit_phase_label( (string) ( $audit_bounds['phase'] ?? '' ) ) );
            $row( __( 'Interrupted?',                    'tmwseo' ), ! empty( $audit_bounds['interrupted'] ) ? 'yes' : 'no' );
            echo '</table>';
            echo '<p style="padding:8px 12px;margin:0;font-size:11px;color:#7d7d7d;">';
            echo esc_html__( '"Full Audit" is bounded by these per-phase budgets — it is not an unlimited crawl. Numbers above show what actually ran.', 'tmwseo' );
            echo '</p>';
            echo '</details>';
        }

        // ── Proposed data panel (if a pipeline run returned data pending review) ──
        if ( is_array( $proposed ) && ! empty( $proposed['merged'] ) ) {
            $merged = $proposed['merged'];
            echo '<div style="background:#fff3cd;border-left:4px solid #ffc107;padding:10px 14px;margin-bottom:14px;">';
            echo '<strong>' . esc_html__( 'Proposed data (pending review):', 'tmwseo' ) . '</strong>';
            echo '<table style="margin-top:8px;width:100%;border-collapse:collapse;">';
            foreach ( $merged as $field => $value ) {
                // platform_candidates is an array-of-arrays — skip it here;
                // it is rendered by the dedicated candidate audit table below.
                if ( $field === 'platform_candidates' ) { continue; }
                // external_candidates rendered in its own operator-review lane.
                if ( $field === 'external_candidates' ) { continue; }
                // social_urls rendered as a selectable promote block below —
                // never display raw research URLs as plain text in this table.
                if ( $field === 'social_urls' ) { continue; }
                // Diagnostics render in their own operator-focused panels below.
                if ( $field === 'field_confidence' || $field === 'research_diagnostics' ) { continue; }
                if ( $value === null || $value === '' || $value === [] ) { continue; }
                // For flat arrays (platform_names, social_urls, etc.), implode scalars only.
                if ( is_array( $value ) ) {
                    $display = implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
                } else {
                    $display = (string) $value;
                }
                if ( $display === '' ) { continue; }
                echo '<tr><td style="padding:2px 8px 2px 0;font-weight:600;white-space:nowrap;">'
                    . esc_html( str_replace( '_', ' ', ucfirst( $field ) ) ) . '</td>';
                echo '<td style="padding:2px 0;">' . esc_html( $display ) . '</td></tr>';
            }
            echo '</table>';
            // ── Candidate review section (trusted / promote / rejected) ──────
            self::render_candidate_review_section( $merged, $post->ID );

            // ── Research Diagnostics (collapsed — for operators debugging runs) ─
            $field_confidence = isset( $merged['field_confidence'] ) && is_array( $merged['field_confidence'] )
                ? $merged['field_confidence']
                : [];
            $diagnostics = isset( $merged['research_diagnostics'] ) && is_array( $merged['research_diagnostics'] )
                ? $merged['research_diagnostics']
                : [];
            if ( ! empty( $field_confidence ) || ! empty( $diagnostics ) ) {
                echo '<details style="margin-top:10px;">';
                echo '<summary style="cursor:pointer;font-weight:600;padding:4px 0;">'
                    . esc_html__( 'Research Diagnostics', 'tmwseo' )
                    . '</summary>';

                if ( ! empty( $field_confidence ) ) {
                    echo '<p style="margin:8px 0 4px;font-weight:600;">' . esc_html__( 'Field Confidence', 'tmwseo' ) . '</p>';
                    echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                    echo '<tr style="background:#f6f7f7;">'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Field', 'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Confidence', 'tmwseo' ) . '</th>'
                        . '</tr>';
                    foreach ( $field_confidence as $field_key => $field_score ) {
                        echo '<tr>'
                            . '<td style="padding:3px 6px;">' . esc_html( str_replace( '_', ' ', ucfirst( (string) $field_key ) ) ) . '</td>'
                            . '<td style="padding:3px 6px;">' . esc_html( (string) $field_score ) . '%</td>'
                            . '</tr>';
                    }
                    echo '</table>';
                }

                $query_stats = isset( $diagnostics['query_stats'] ) && is_array( $diagnostics['query_stats'] )
                    ? $diagnostics['query_stats']
                    : [];
                if ( ! empty( $query_stats ) ) {
                    echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Query Coverage', 'tmwseo' ) . '</p>';
                    echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                    echo '<tr style="background:#f6f7f7;">'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Family', 'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Results', 'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Status', 'tmwseo' ) . '</th>'
                        . '</tr>';
                    foreach ( $query_stats as $row ) {
                        $ok = ! empty( $row['ok'] );
                        echo '<tr>'
                            . '<td style="padding:3px 6px;">' . esc_html( (string) ( $row['family'] ?? 'query' ) ) . '</td>'
                            . '<td style="padding:3px 6px;">' . esc_html( (string) ( $row['result_count'] ?? 0 ) ) . '</td>'
                            . '<td style="padding:3px 6px;">'
                            . ( $ok ? '<span style="color:#1d6a2e;">ok</span>' : '<span style="color:#8a1a1a;">' . esc_html( (string) ( $row['error'] ?? 'failed' ) ) . '</span>' )
                            . '</td>'
                            . '</tr>';
                    }
                    echo '</table>';
                }

                $source_classes = isset( $diagnostics['source_class_counts'] ) && is_array( $diagnostics['source_class_counts'] )
                    ? $diagnostics['source_class_counts']
                    : [];
                if ( ! empty( $source_classes ) ) {
                    echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Source Classes', 'tmwseo' ) . '</p>';
                    echo '<p style="margin:0 0 6px;">';
                    $bits = [];
                    foreach ( $source_classes as $class_name => $class_count ) {
                        $bits[] = esc_html( (string) $class_name . ': ' . (string) $class_count );
                    }
                    echo implode( ' · ', $bits );
                    echo '</p>';
                }

                $hub_stats = isset( $diagnostics['hub_expansion'] ) && is_array( $diagnostics['hub_expansion'] )
                    ? $diagnostics['hub_expansion']
                    : [];
                if ( ! empty( $hub_stats ) ) {
                    echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Hub Expansion', 'tmwseo' ) . '</p>';
                    echo '<p style="margin:0 0 6px;">'
                        . esc_html( sprintf(
                            'attempted: %d · expanded profiles: %d · fetch failures: %d · cache hits: %d',
                            (int) ( $hub_stats['attempted'] ?? 0 ),
                            (int) ( $hub_stats['expanded_profiles'] ?? 0 ),
                            (int) ( $hub_stats['fetch_failures'] ?? 0 ),
                            (int) ( $hub_stats['cached_hits'] ?? 0 )
                        ) )
                        . '</p>';
                }

                // discovered_handles may be a flat string[] (pre-v5.0.0) or an
                // array-of-objects with provenance (v5.0.0+). Render both shapes.
                $raw_handles = isset( $diagnostics['discovered_handles'] ) && is_array( $diagnostics['discovered_handles'] )
                    ? $diagnostics['discovered_handles']
                    : [];
                $handles = [];
                foreach ( $raw_handles as $h ) {
                    if ( is_string( $h ) && $h !== '' ) {
                        $handles[] = $h;
                    } elseif ( is_array( $h ) && isset( $h['handle'] ) && (string) $h['handle'] !== '' ) {
                        $label = (string) $h['handle'];
                        $plat  = (string) ( $h['platform'] ?? '' );
                        $src   = (string) ( $h['source'] ?? '' );
                        if ( $plat !== '' ) {
                            $label .= ' (' . $plat;
                            if ( $src === 'pass_two_confirmation' ) {
                                $label .= ', confirmed';
                            }
                            $label .= ')';
                        }
                        $handles[] = $label;
                    }
                }
                if ( ! empty( $handles ) ) {
                    echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Discovered Handles', 'tmwseo' ) . '</p>';
                    echo '<p style="margin:0 0 6px;">' . esc_html( implode( ', ', $handles ) ) . '</p>';
                }

                $evidence_items = isset( $diagnostics['evidence_items'] ) && is_array( $diagnostics['evidence_items'] )
                    ? $diagnostics['evidence_items']
                    : [];
                if ( ! empty( $evidence_items ) ) {
                    echo '<p style="margin:10px 0 4px;font-weight:600;">' . esc_html__( 'Evidence Samples', 'tmwseo' ) . '</p>';
                    echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                    echo '<tr style="background:#f6f7f7;">'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Type', 'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Family / Alias', 'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'URL', 'tmwseo' ) . '</th>'
                        . '</tr>';
                    foreach ( $evidence_items as $evidence ) {
                        $sample_url  = (string) ( $evidence['url'] ?? '' );
                        $display_url = strlen( $sample_url ) > 70 ? substr( $sample_url, 0, 70 ) . '…' : $sample_url;
                        $family_cell = esc_html( (string) ( $evidence['query_family'] ?? '' ) );
                        $ev_alias    = trim( (string) ( $evidence['alias_source'] ?? '' ) );
                        if ( $ev_alias !== '' ) {
                            $family_cell .= ' <em style="color:#666;">(alias: ' . esc_html( $ev_alias ) . ')</em>';
                        }
                        echo '<tr>'
                            . '<td style="padding:3px 6px;">' . esc_html( (string) ( $evidence['class'] ?? '' ) ) . '</td>'
                            . '<td style="padding:3px 6px;">' . $family_cell . '</td>'
                            . '<td style="padding:3px 6px;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                            . '<a href="' . esc_url( $sample_url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $sample_url ) . '">'
                            . esc_html( $display_url )
                            . '</a></td>'
                            . '</tr>';
                    }
                    echo '</table>';
                }

                echo '</details>';
            }

            // ── Apply / Discard buttons ───────────────────────────────────────
            $apply_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=tmwseo_apply_model_research&post_id=' . $post->ID ),
                'tmwseo_apply_research_' . $post->ID
            );
            $discard_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=tmwseo_discard_research&post_id=' . $post->ID ),
                'tmwseo_discard_research_' . $post->ID
            );
            echo '<p style="margin-top:10px;margin-bottom:0;">';
            echo '<a class="button button-primary" href="' . esc_url( $apply_url ) . '">'
                . esc_html__( 'Apply Proposed Data', 'tmwseo' ) . '</a> ';
            echo '<a class="button" href="' . esc_url( $discard_url ) . '" style="margin-left:6px;">'
                . esc_html__( 'Discard', 'tmwseo' ) . '</a>';
            echo '</p>';
            echo '</div>';
        }

        // ── Manual-entry fields ───────────────────────────────────────────
        echo '<table class="form-table" style="margin-top:0;">';
        ModelHelper::field_text(
            'tmwseo_research_display_name', $display_name,
            __( 'Display Name', 'tmwseo' ),
            __( 'The public name shown in titles and descriptions. Defaults to post title if blank.', 'tmwseo' )
        );
        ModelHelper::field_text(
            'tmwseo_research_aliases', $aliases,
            __( 'Aliases', 'tmwseo' ),
            __( 'Comma-separated alternative names or stage names (e.g. OhhAisha, AishaX). Used by the research engine to search for all profiles — add every known alias before clicking Research Now.', 'tmwseo' )
        );
        ModelHelper::field_textarea(
            'tmwseo_research_bio', $bio,
            __( 'Short Bio', 'tmwseo' ),
            __( 'A concise, non-graphic biographical note (150-300 words). Used for SEO copy suggestions only — never auto-published.', 'tmwseo' ),
            5
        );
        ModelHelper::field_text(
            'tmwseo_research_platform_names', $platforms,
            __( 'Platform Names', 'tmwseo' ),
            __( 'Comma-separated names of platforms where this model is active (e.g. Chaturbate, Stripchat).', 'tmwseo' )
        );
        ModelHelper::field_textarea(
            'tmwseo_research_social_urls', $social_urls,
            __( 'Social / Profile URLs', 'tmwseo' ),
            __( 'One URL per line. Official profiles, social accounts, or verified links only.', 'tmwseo' ),
            4
        );
        ModelHelper::field_text(
            'tmwseo_research_country', $country,
            __( 'Country', 'tmwseo' ),
            __( 'Country name or ISO 3166-1 alpha-2 code. Only fill if confidently known.', 'tmwseo' )
        );
        ModelHelper::field_text(
            'tmwseo_research_language', $language,
            __( 'Language', 'tmwseo' ),
            __( 'Primary language (e.g. "English", "es"). Only fill if confidently known.', 'tmwseo' )
        );
        ModelHelper::field_textarea(
            'tmwseo_research_source_urls', $source_urls,
            __( 'Source URLs Used', 'tmwseo' ),
            __( 'One URL per line. Document the sources you used to fill in the fields above.', 'tmwseo' ),
            3
        );
        ModelHelper::field_number(
            'tmwseo_research_confidence', $confidence,
            __( 'Confidence Score (0-100)', 'tmwseo' ),
            __( 'How confident are you in the data above? 0 = uncertain, 100 = verified.', 'tmwseo' )
        );
        ModelHelper::field_textarea(
            'tmwseo_research_notes', $notes,
            __( 'Admin Notes', 'tmwseo' ),
            __( 'Free-form notes about this model\'s research state. For internal use only.', 'tmwseo' ),
            3
        );
        ModelHelper::field_textarea(
            'tmwseo_editor_seed_summary', $seed_summary,
            __( 'Editor Seed: Short Summary', 'tmwseo' ),
            __( 'High-trust operator summary used as the primary content anchor for intro/about sections.', 'tmwseo' ),
            3
        );
        ModelHelper::field_text(
            'tmwseo_editor_seed_tags', $seed_tags,
            __( 'Editor Seed: Known-for / Tags', 'tmwseo' ),
            __( 'Comma-separated known-for tags (e.g. friendly chat, cosplay nights, bilingual streams).', 'tmwseo' )
        );
        ModelHelper::field_textarea(
            'tmwseo_editor_seed_platform_notes', $seed_platform_notes,
            __( 'Editor Seed: Platform Notes', 'tmwseo' ),
            __( 'One note per line. Optional format: Platform: note. Used in platform comparison and feature framing.', 'tmwseo' ),
            4
        );
        ModelHelper::field_textarea(
            'tmwseo_editor_seed_confirmed_facts', $seed_confirmed_facts,
            __( 'Editor Seed: Confirmed Facts', 'tmwseo' ),
            __( 'One confirmed fact per line. These facts are treated as trusted for About/FAQ generation.', 'tmwseo' ),
            4
        );
        ModelHelper::field_textarea(
            'tmwseo_editor_seed_avoid_claims', $seed_avoid_claims,
            __( 'Editor Seed: Claims to Avoid / Unknowns', 'tmwseo' ),
            __( 'One line per claim that is unknown or should not be asserted as true.', 'tmwseo' ),
            3
        );
        ModelHelper::field_text(
            'tmwseo_editor_seed_tone_hint', $seed_tone_hint,
            __( 'Editor Seed: Tone Hint (Optional)', 'tmwseo' ),
            __( 'Optional writing guidance for AI output, e.g. concise and neutral, warm and practical.', 'tmwseo' )
        );
        // ── External evidence textareas (v5.8.7) ──────────────────────────────
        // Operator-pasted evidence used to seed the 3 sections that prepend
        // above the generated model body: About / Turn Ons / Private Chat
        // Options. Saved as plain text; humanized at generation time by
        // \TMWSEO\Engine\Content\ModelResearchEvidence.
        $seed_ext_bio        = (string) get_post_meta( $post->ID, ModelHelper::META_SEED_EXTERNAL_BIO, true );
        $seed_ext_turn_ons   = (string) get_post_meta( $post->ID, ModelHelper::META_SEED_EXTERNAL_TURN_ONS, true );
        $seed_ext_priv_chat  = (string) get_post_meta( $post->ID, ModelHelper::META_SEED_EXTERNAL_PRIVATE_CHAT, true );
        ModelHelper::field_textarea(
            'tmwseo_seed_external_bio', $seed_ext_bio,
            __( 'Editor Seed: External Bio Evidence', 'tmwseo' ),
            __( 'Paste raw bio evidence from external profile pages. The plugin will rewrite this into a humanized "About {Model}" section above the generated content. Never copied verbatim. First-person and HTML entities are stripped automatically.', 'tmwseo' ),
            5
        );
        ModelHelper::field_textarea(
            'tmwseo_seed_external_turn_ons', $seed_ext_turn_ons,
            __( 'Editor Seed: External Turn Ons Evidence', 'tmwseo' ),
            __( 'Paste raw turn-ons evidence from external profile pages. The plugin will rewrite this into a safe "Turn Ons" section above the generated content. Crude phrasing ("darling", "horny", first-person) is stripped automatically.', 'tmwseo' ),
            4
        );
        ModelHelper::field_textarea(
            'tmwseo_seed_external_private_chat', $seed_ext_priv_chat,
            __( 'Editor Seed: External Private Chat Evidence', 'tmwseo' ),
            __( 'Paste raw private-chat option list (commas, lines, or bullets all work). Explicit terms (anal sex, deepthroat, double penetration, squirt, cum, etc.) are removed automatically. Acronyms (JOI, POV, ASMR, C2C) preserved uppercase. Capped at 14 items.', 'tmwseo' ),
            4
        );
        echo '</table>';

        // ── Bio Evidence sub-panel ────────────────────────────────────────
        // Gate: bio appears on page ONLY when Review Status = reviewed AND
        // Bio Summary is not empty. All other states = no bio shown.
        // Write original prose here — never paste raw third-party text.
        // WPS LiveJasmin data (if available) should be manually reviewed and
        // paraphrased into Bio Summary by an editor, not pasted verbatim.
        echo '<hr style="margin:18px 0 14px;">';
        echo '<h4 style="margin:0 0 10px;font-size:13px;color:#1e293b;">' . esc_html__( 'Bio Evidence', 'tmwseo' ) . '</h4>';
        echo '<p style="font-size:11px;color:#6b7280;margin:0 0 12px;line-height:1.45;">';
        echo esc_html__( 'Bio appears on the model page only when Review Status = Reviewed and Bio Summary is filled. Write original prose — never paste copied third-party text here.', 'tmwseo' );
        echo '</p>';
        echo '<table class="form-table" style="margin-top:0;">';

        // Review Status (gate field — must be set last after reviewing content)
        echo '<tr>';
        echo '<th scope="row" style="width:160px;"><label for="tmwseo_bio_review_status">' . esc_html__( 'Review Status', 'tmwseo' ) . '</label></th>';
        echo '<td>';
        echo '<select name="tmwseo_bio_review_status" id="tmwseo_bio_review_status">';
        $statuses = [ '' => '— Not set —', 'draft' => 'Draft', 'reviewed' => 'Reviewed (live)' ];
        foreach ( $statuses as $val => $label ) {
            $selected = selected( $bio_review_status, $val, false );
            echo '<option value="' . esc_attr( $val ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Set to Reviewed to enable the bio on the public page. Draft = saved but not shown.', 'tmwseo' ) . '</p>';
        echo '</td></tr>';

        // Bio Summary (the actual on-page text — must be original, 60-110 words)
        ModelHelper::field_textarea(
            'tmwseo_bio_summary', $bio_summary,
            __( 'Bio Summary', 'tmwseo' ),
            __( 'Original, editor-written bio (60–110 words). Non-explicit. No copied third-party text. Only published when Review Status = Reviewed.', 'tmwseo' ),
            4
        );

        // Source Type
        echo '<tr>';
        echo '<th scope="row" style="width:160px;"><label for="tmwseo_bio_source_type">' . esc_html__( 'Source Type', 'tmwseo' ) . '</label></th>';
        echo '<td>';
        echo '<select name="tmwseo_bio_source_type" id="tmwseo_bio_source_type">';
        $source_types = [ '' => '— Not set —', 'editor' => 'Editor-written', 'platform_page' => 'Platform page (reviewed)', 'press' => 'Press / interview', 'wps_import' => 'WPS LiveJasmin import (reviewed)', 'none' => 'None / unknown' ];
        foreach ( $source_types as $val => $label ) {
            $selected = selected( $bio_source_type, $val, false );
            echo '<option value="' . esc_attr( $val ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'What kind of source backed this bio? For audit trail only — not shown publicly.', 'tmwseo' ) . '</p>';
        echo '</td></tr>';

        // Source Label
        ModelHelper::field_text(
            'tmwseo_bio_source_label', $bio_source_label,
            __( 'Source Label', 'tmwseo' ),
            __( 'Short human label for the source (e.g. "LiveJasmin profile page"). For audit trail only.', 'tmwseo' )
        );

        // Source URL
        ModelHelper::field_text(
            'tmwseo_bio_source_url', $bio_source_url,
            __( 'Source URL', 'tmwseo' ),
            __( 'URL of the source page reviewed. For audit trail only — never auto-linked.', 'tmwseo' )
        );

        // Source Facts (raw evidence notes — not published)
        ModelHelper::field_textarea(
            'tmwseo_bio_source_facts', $bio_source_facts,
            __( 'Source Facts / Evidence Notes', 'tmwseo' ),
            __( 'One fact per line from the source page (e.g. "Active since 2019", "Speaks English and Spanish"). Used as AI prompt evidence — never published raw.', 'tmwseo' ),
            4
        );

        // Reviewed At (date stamp for audit trail)
        ModelHelper::field_text(
            'tmwseo_bio_reviewed_at', $bio_reviewed_at,
            __( 'Reviewed At', 'tmwseo' ),
            __( 'Date the bio was last reviewed (e.g. 2025-04-25). For audit trail only.', 'tmwseo' )
        );

        echo '</table>';


        // ── Save button ───────────────────────────────────────────────────
        echo '<p>';
        echo '<button type="submit" class="button button-primary" name="tmwseo_research_manual_save" value="1">';
        echo esc_html__( 'Save Research Fields', 'tmwseo' );
        echo '</button>';
        echo '<span style="margin-left:10px;color:#666;font-size:12px;">';
        echo esc_html__( 'Saved with the post. Data is never auto-published.', 'tmwseo' );
        echo '</span>';
        echo '</p>';
    }

    // ── Candidate review section ─────────────────────────────────────────

    /**
     * Render the structured candidate review section inside the proposed-data panel.
     *
     * UNIFIED GROUP VIEW: Both trusted extractions and external/unverified candidates
     * are merged into a single view organised by platform group (Social / Fansites /
     * Cam Platforms / Tube Sites / Link Hubs / Other). Within each group, trusted rows
     * appear first (green table), unverified cards appear below (blue cards). This
     * eliminates the confusing duplicate "Social" / "Fansites" section headers.
     *
     * Group-aware type dropdown: the promote type <select> is pre-filtered to show
     * only types relevant for the current group (e.g. cam rows only show cam-relevant
     * types, social rows only show social types).
     *
     * Trust contract unchanged:
     *   - Trusted rows = strict-parser-backed structured extractions.
     *   - Unverified cards = found during research, never auto-promoted.
     *   - Every promote action requires an explicit click.
     *
     * @param array<string,mixed> $merged   Merged pipeline output.
     * @param int                 $post_id  Model post ID.
     */
    private static function render_candidate_review_section( array $merged, int $post_id ): void {
        $candidates = isset( $merged['platform_candidates'] ) && is_array( $merged['platform_candidates'] )
            ? $merged['platform_candidates'] : [];
        $external   = isset( $merged['external_candidates'] ) && is_array( $merged['external_candidates'] )
            ? $merged['external_candidates'] : [];

        $successful = array_values( array_filter( $candidates, static fn( $c ) => ! empty( $c['success'] ) ) );
        $rejected   = array_values( array_filter( $candidates, static fn( $c ) => empty( $c['success'] ) ) );

        $promote_action = admin_url( 'admin-post.php' );
        $promote_nonce  = wp_create_nonce( \TMWSEO\Engine\Model\VerifiedLinks::NONCE_PROMOTE . $post_id );

        // ── Group definitions ─────────────────────────────────────────────────
        $group_meta = [
            'social'  => [
                'label'       => __( '💬 Social',        'tmwseo' ),
                'header_bg'   => '#e8f4fd', 'border'      => '#aed6f1',
                'title_color' => '#1a5276', 'row_bg'      => '#f0f8ff',
                'row_border'  => '#aed6f1', 'head_bg'     => '#d6eaf8',
                'types'       => [ 'x', 'instagram', 'facebook', 'tiktok', 'youtube', 'other' ],
            ],
            'fansite' => [
                'label'       => __( '💖 Fansites',      'tmwseo' ),
                'header_bg'   => '#fdf2f8', 'border'      => '#d7bde2',
                'title_color' => '#6c3483', 'row_bg'      => '#fdf2f8',
                'row_border'  => '#d7bde2', 'head_bg'     => '#f5eef8',
                'types'       => [ 'onlyfans', 'fansly', 'fancentro', 'personal_site', 'other' ],
            ],
            'cam'     => [
                'label'       => __( '🎥 Cam Platforms',  'tmwseo' ),
                'header_bg'   => '#f0fff4', 'border'      => '#b7e4c7',
                'title_color' => '#1d6a2e', 'row_bg'      => '#f0fff4',
                'row_border'  => '#b7e4c7', 'head_bg'     => '#d8f3dc',
                'types'       => [ 'streamate', 'other' ],
            ],
            'tube'    => [
                'label'       => __( '📹 Tube Sites',    'tmwseo' ),
                'header_bg'   => '#fef9f0', 'border'      => '#f5cba7',
                'title_color' => '#784212', 'row_bg'      => '#fef9f0',
                'row_border'  => '#f5cba7', 'head_bg'     => '#fdebd0',
                'types'       => [ 'pornhub', 'other' ],
            ],
            'linkhub' => [
                'label'       => __( '🔗 Link Hubs',     'tmwseo' ),
                'header_bg'   => '#fefefe', 'border'      => '#ced4da',
                'title_color' => '#495057', 'row_bg'      => '#fafafa',
                'row_border'  => '#dee2e6', 'head_bg'     => '#e9ecef',
                'types'       => [ 'linktree', 'personal_site', 'other' ],
            ],
            'other'   => [
                'label'       => __( '🌐 Other',          'tmwseo' ),
                'header_bg'   => '#fff8e1', 'border'      => '#ffe082',
                'title_color' => '#7d5c00', 'row_bg'      => '#fffde7',
                'row_border'  => '#ffe082', 'head_bg'     => '#fff9c4',
                'types'       => array_keys( \TMWSEO\Engine\Model\VerifiedLinks::TYPE_LABELS ),
            ],
        ];

        // ── Build group-keyed buckets for trusted rows ────────────────────────
        $trusted_buckets = array_fill_keys( array_keys( $group_meta ), [] );
        foreach ( $successful as $idx => $c ) {
            $slug  = (string) ( $c['normalized_platform'] ?? '' );
            $group = \TMWSEO\Engine\Platform\PlatformRegistry::get_group( $slug );
            if ( ! isset( $trusted_buckets[ $group ] ) ) { $group = 'other'; }
            $trusted_buckets[ $group ][] = [ 'idx' => $idx, 'c' => $c ];
        }

        // ── Build group-keyed buckets for external/unverified candidates ──────
        $ext_buckets = array_fill_keys( array_keys( $group_meta ), [] );
        foreach ( $external as $eidx => $ec ) {
            $slug  = (string) ( $ec['detected_platform'] ?? '' );
            $pd    = \TMWSEO\Engine\Platform\PlatformRegistry::get( $slug );
            $group = $pd ? ( $pd['group'] ?? 'other' ) : self::classify_external_candidate( $ec );
            if ( ! isset( $ext_buckets[ $group ] ) ) { $group = 'other'; }
            $ext_buckets[ $group ][] = [ 'eidx' => $eidx, 'ec' => $ec ];
        }

        // ── Check if there is anything to show at all ─────────────────────────
        $has_trusted  = ! empty( $successful );
        $has_external = ! empty( $external );
        if ( ! $has_trusted && ! $has_external ) {
            // Fall through to the rejected block and empty-state below.
        } else {
            // ── Unified group header ──────────────────────────────────────────
            $total_trusted  = count( $successful );
            $total_external = count( $external );
            echo '<div style="margin-top:10px;">';
            echo '<p style="margin:0 0 8px;font-weight:600;color:#333;">';
            if ( $has_trusted ) {
                printf(
                    esc_html__( '✓ Trusted Extractions (%d)', 'tmwseo' ),
                    $total_trusted
                );
            }
            if ( $has_trusted && $has_external ) { echo ' &nbsp;·&nbsp; '; }
            if ( $has_external ) {
                printf(
                    esc_html__( '🔍 Unverified Candidates (%d) — review individually', 'tmwseo' ),
                    $total_external
                );
            }
            echo '</p>';

            // ── Iterate groups once — show trusted + unverified within each ──
            foreach ( $group_meta as $gkey => $gm ) {
                $t_rows  = $trusted_buckets[ $gkey ] ?? [];
                $e_rows  = $ext_buckets[ $gkey ] ?? [];
                if ( empty( $t_rows ) && empty( $e_rows ) ) { continue; }

                $total_in_group = count( $t_rows ) + count( $e_rows );
                $open = in_array( $gkey, [ 'social', 'fansite', 'cam' ], true ) ? ' open' : '';

                echo '<details' . $open . ' style="margin-bottom:6px;border:1px solid ' . esc_attr( $gm['border'] ) . ';border-radius:4px;">';
                echo '<summary style="cursor:pointer;padding:6px 10px;background:' . esc_attr( $gm['header_bg'] ) . ';color:' . esc_attr( $gm['title_color'] ) . ';font-weight:600;border-radius:4px;user-select:none;">';
                echo esc_html( $gm['label'] );
                if ( ! empty( $t_rows ) ) {
                    echo ' <span style="font-weight:400;font-size:11px;background:' . esc_attr( $gm['head_bg'] ) . ';padding:1px 5px;border-radius:3px;">✓ ' . count( $t_rows ) . ' trusted</span>';
                }
                if ( ! empty( $e_rows ) ) {
                    echo ' <span style="font-weight:400;font-size:11px;background:#ebf5fb;padding:1px 5px;border-radius:3px;">🔍 ' . count( $e_rows ) . ' unverified</span>';
                }
                echo '</summary>';

                // ── Trusted rows (table) ──────────────────────────────────────
                if ( ! empty( $t_rows ) ) {
                    $group_types = $gm['types'];
                    echo '<div style="padding:6px 8px 2px;background:' . esc_attr( $gm['row_bg'] ) . ';border-bottom:' . ( empty( $e_rows ) ? 'none' : '2px solid ' . esc_attr( $gm['border'] ) ) . ';">';
                    echo '<div style="font-size:11px;font-weight:600;color:' . esc_attr( $gm['title_color'] ) . ';margin-bottom:4px;">✓ Trusted — strict parser-backed</div>';
                    echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                    echo '<tr style="background:' . esc_attr( $gm['head_bg'] ) . ';">'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Platform',       'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Profile URL',    'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Username',       'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Provider',       'tmwseo' ) . '</th>'
                        . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Actions',        'tmwseo' ) . '</th>'
                        . '</tr>';

                    foreach ( $t_rows as $entry ) {
                        $idx      = (int) $entry['idx'];
                        $row_c    = $entry['c'];
                        $pd       = \TMWSEO\Engine\Platform\PlatformRegistry::get( (string) ( $row_c['normalized_platform'] ?? '' ) );
                        $plabel   = $pd ? esc_html( (string) ( $pd['name'] ?? '' ) ) : esc_html( (string) ( $row_c['normalized_platform'] ?? '' ) );
                        $norm_url = (string) ( $row_c['normalized_url'] ?? $row_c['source_url'] ?? '' );
                        $url_disp = strlen( $norm_url ) > 50 ? substr( $norm_url, 0, 50 ) . '…' : $norm_url;
                        $provider = esc_html( (string) ( $row_c['_provider'] ?? '—' ) );
                        $alias    = trim( (string) ( $row_c['_alias_source'] ?? '' ) );
                        $prov_cell = $alias !== ''
                            ? $provider . ' <em style="color:#555;">(alias: ' . esc_html( $alias ) . ')</em>'
                            : $provider;
                        $vl_type  = self::platform_slug_to_vl_type( (string) ( $row_c['normalized_platform'] ?? '' ) );

                        echo '<tr style="border-top:1px solid ' . esc_attr( $gm['row_border'] ) . ';" id="tmwseo-trusted-row-' . $idx . '">'
                            . '<td style="padding:3px 6px;font-weight:600;">' . $plabel . '</td>'
                            . '<td style="padding:3px 6px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                            . '<a href="' . esc_url( $norm_url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $norm_url ) . '" style="color:' . esc_attr( $gm['title_color'] ) . ';">'
                            . esc_html( $url_disp ) . '</a></td>'
                            . '<td style="padding:3px 6px;font-family:monospace;font-size:11px;">' . esc_html( (string) ( $row_c['username'] ?? '—' ) ) . '</td>'
                            . '<td style="padding:3px 6px;font-size:11px;">' . $prov_cell . '</td>'
                            . '<td style="padding:4px 6px;min-width:200px;">';

                        if ( $norm_url !== '' && class_exists( '\TMWSEO\Engine\Model\VerifiedLinks' ) ) {
                            $all_types = \TMWSEO\Engine\Model\VerifiedLinks::TYPE_LABELS;
                            // Filter to group-relevant types only
                            $filtered_types = array_intersect_key( $all_types, array_flip( $group_types ) );
                            if ( empty( $filtered_types ) ) { $filtered_types = $all_types; }
                            asort( $filtered_types );
                            $uniq_t = 'trusted_' . $idx;
                            echo '<form method="post" action="' . esc_url( $promote_action ) . '">';
                            echo '<input type="hidden" name="action"               value="tmwseo_promote_to_verified">';
                            echo '<input type="hidden" name="post_id"              value="' . (int) $post_id . '">';
                            echo '<input type="hidden" name="tmwseo_promote_nonce" value="' . esc_attr( $promote_nonce ) . '">';
                            echo '<input type="hidden" name="tmwseo_promote_url[0]" value="' . esc_attr( $norm_url ) . '">';
                            echo '<input type="hidden" name="tmwseo_outbound_type[0]" value="direct_profile">';
                            echo '<div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">';
                            echo '<select name="tmwseo_promote_type[0]" style="font-size:11px;max-width:150px;">';
                            foreach ( $filtered_types as $tv => $tl ) {
                                printf( '<option value="%s"%s>%s</option>', esc_attr( $tv ), selected( $vl_type, $tv, false ), esc_html( $tl ) );
                            }
                            echo '</select>';
                            echo '<input type="text" name="tmwseo_outbound_url[0]" value="" placeholder="' . esc_attr__( 'Outbound URL (optional)', 'tmwseo' ) . '" style="font-size:11px;flex:1;min-width:100px;font-family:monospace;" />';
                            echo '<button type="submit" class="button button-primary button-small" style="font-size:11px;">' . esc_html__( 'Promote', 'tmwseo' ) . '</button></form>';
                        } else {
                            echo '<div style="display:flex;gap:4px;">';
                        }
                        echo '<button type="button" class="button button-small" style="font-size:11px;color:#8a1a1a;" '
                            . 'onclick="document.getElementById(' . json_encode( 'tmwseo-trusted-row-' . $idx ) . ').style.display=\'none\';">'
                            . esc_html__( 'Dismiss', 'tmwseo' ) . '</button>';
                        echo '</div>';
                        echo '</td></tr>';
                    }
                    echo '</table></div>';
                }

                // ── Unverified/external cards ─────────────────────────────────
                if ( ! empty( $e_rows ) ) {
                    $group_types = $gm['types'];
                    echo '<div style="padding:6px 8px;background:#fff;">';
                    echo '<div style="font-size:11px;font-weight:600;color:#1a5276;margin-bottom:4px;">🔍 Unverified — review individually before promoting</div>';
                    foreach ( $e_rows as $entry ) {
                        $eidx      = (int) $entry['eidx'];
                        $ec        = $entry['ec'];
                        $ec_url    = (string) ( $ec['url'] ?? '' );
                        $ec_label  = esc_html( (string) ( $ec['label'] ?? $ec['detected_platform'] ?? '' ) );
                        $ec_type   = (string) ( $ec['suggested_type'] ?? 'other' );
                        $ec_conf   = (string) ( $ec['confidence'] ?? 'medium' );
                        $ec_alias  = trim( (string) ( $ec['_alias_source'] ?? '' ) );
                        $ec_disp   = strlen( $ec_url ) > 60 ? substr( $ec_url, 0, 60 ) . '…' : $ec_url;
                        $conf_color = match ( $ec_conf ) { 'high' => '#1d6a2e', 'medium' => '#7d5c00', default => '#50575e' };
                        $conf_bg    = match ( $ec_conf ) { 'high' => '#edfaef', 'medium' => '#fcf9e8', default => '#f0f0f1' };
                        $alias_note = $ec_alias !== '' ? ' <em style="color:#666;font-size:11px;">(via alias: ' . esc_html( $ec_alias ) . ')</em>' : '';
                        $row_id     = 'tmwseo-ext-row-' . $eidx;

                        echo '<div id="' . esc_attr( $row_id ) . '" style="border:1px solid #d4e6f1;border-radius:3px;padding:7px 10px;margin-bottom:6px;background:#f8fcff;">';
                        echo '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:5px;">';
                        echo '<strong>' . $ec_label . '</strong>' . $alias_note;
                        echo '<span style="background:' . esc_attr( $conf_bg ) . ';color:' . esc_attr( $conf_color ) . ';padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;">' . esc_html( ucfirst( $ec_conf ) ) . '</span>';
                        echo '<a href="' . esc_url( $ec_url ) . '" target="_blank" rel="noopener" style="font-size:11px;font-family:monospace;color:#1a5276;word-break:break-all;" title="' . esc_attr( $ec_url ) . '">' . esc_html( $ec_disp ) . '</a>';
                        echo '</div>';

                        if ( $ec_url !== '' && class_exists( '\TMWSEO\Engine\Model\VerifiedLinks' ) ) {
                            $uniq = 'ext_' . $eidx;
                            $all_types = \TMWSEO\Engine\Model\VerifiedLinks::TYPE_LABELS;
                            $filtered_types = array_intersect_key( $all_types, array_flip( $group_types ) );
                            if ( empty( $filtered_types ) ) { $filtered_types = $all_types; }
                            asort( $filtered_types );
                            $default_outbound = ( $ec['suggested_type'] ?? '' ) === 'personal_site' ? 'personal_site' : 'direct_profile';

                            echo '<form method="post" action="' . esc_url( $promote_action ) . '" style="margin:0;">';
                            echo '<input type="hidden" name="action"               value="tmwseo_promote_to_verified">';
                            echo '<input type="hidden" name="post_id"              value="' . (int) $post_id . '">';
                            echo '<input type="hidden" name="tmwseo_promote_nonce" value="' . esc_attr( $promote_nonce ) . '">';
                            echo '<input type="hidden" name="tmwseo_promote_url[0]"  value="' . esc_attr( $ec_url ) . '">';
                            echo '<input type="hidden" name="tmwseo_promote_type[0]" value="' . esc_attr( $ec_type ) . '">';
                            echo '<div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;">';
                            echo '<select name="tmwseo_outbound_type[0]" style="font-size:11px;" title="' . esc_attr__( 'Link type', 'tmwseo' ) . '">';
                            $outbound_opts = [ 'direct_profile' => 'Direct profile', 'personal_site' => 'Personal site', 'website' => 'Website', 'social' => 'Social' ];
                            foreach ( $outbound_opts as $ov => $ol ) {
                                printf( '<option value="%s"%s>%s</option>', esc_attr( $ov ), selected( $default_outbound, $ov, false ), esc_html( $ol ) );
                            }
                            echo '</select>';
                            echo '<input type="text" name="tmwseo_outbound_url[0]" id="' . esc_attr( $uniq ) . '_out" value="" placeholder="' . esc_attr( $ec_url ) . '" style="font-size:11px;flex:1;min-width:120px;font-family:monospace;" title="' . esc_attr__( 'Leave blank to use source URL. Enter an affiliate-ready URL if needed.', 'tmwseo' ) . '" />';
                            echo '<button type="submit" class="button button-primary button-small" style="font-size:11px;">' . esc_html__( 'Promote →', 'tmwseo' ) . '</button></form>';
                            echo '<button type="button" class="button button-small" style="font-size:11px;color:#8a1a1a;" '
                                . 'onclick="document.getElementById(' . json_encode( $row_id ) . ').style.display=\'none\';">'
                                . esc_html__( 'Dismiss', 'tmwseo' ) . '</button>';
                            echo '</div>';
                        } else {
                            echo '<button type="button" class="button button-small" style="font-size:11px;color:#8a1a1a;" '
                                . 'onclick="document.getElementById(' . json_encode( $row_id ) . ').style.display=\'none\';">'
                                . esc_html__( 'Dismiss', 'tmwseo' ) . '</button>';
                        }
                        echo '</div>'; // end card
                    }
                    echo '</div>';
                }

                echo '</details>';
            }
            echo '</div>'; // end unified section wrapper
        }

        // ── 3. Rejected / Audit-Only — collapsed, clearly non-promotable ─────
        if ( ! empty( $rejected ) ) {
            echo '<details style="margin-top:8px;border:1px solid #f5c6cb;border-radius:3px;">';
            echo '<summary style="cursor:pointer;padding:6px 10px;background:#fff5f5;color:#8a1a1a;font-weight:600;border-radius:3px;">';
            printf(
                esc_html__( '⚠ Rejected / Audit-Only (%d) — not promotable', 'tmwseo' ),
                count( $rejected )
            );
            echo '</summary>';
            echo '<div style="padding:6px 10px;">';
            echo '<p style="margin:4px 0 8px;font-size:12px;color:#721c24;">';
            echo esc_html__( 'These URLs were rejected during structured extraction. They are shown for audit purposes only. None will be promoted or included in platform outputs.', 'tmwseo' );
            echo '</p>';
            echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            echo '<tr style="background:#f8d7da;">'
                . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Platform', 'tmwseo' ) . '</th>'
                . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Source URL', 'tmwseo' ) . '</th>'
                . '<th style="padding:3px 6px;text-align:left;">' . esc_html__( 'Reject Reason', 'tmwseo' ) . '</th>'
                . '</tr>';
            foreach ( $rejected as $c ) {
                $pd     = PlatformRegistry::get( (string) ( $c['normalized_platform'] ?? '' ) );
                $plabel = $pd ? esc_html( (string) ( $pd['name'] ?? '' ) ) : esc_html( (string) ( $c['normalized_platform'] ?? '' ) );
                $src    = (string) ( $c['source_url'] ?? '' );
                $src_d  = strlen( $src ) > 60 ? substr( $src, 0, 60 ) . '…' : $src;
                $reason = esc_html( ucfirst( str_replace( '_', ' ', (string) ( $c['reject_reason'] ?? 'rejected' ) ) ) );
                echo '<tr style="border-top:1px solid #f5c6cb;">'
                    . '<td style="padding:3px 6px;color:#721c24;">' . $plabel . '</td>'
                    . '<td style="padding:3px 6px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
                    . '<a href="' . esc_url( $src ) . '" target="_blank" rel="noopener" title="' . esc_attr( $src ) . '" style="color:#856404;">'
                    . esc_html( $src_d ) . '</a></td>'
                    . '<td style="padding:3px 6px;color:#721c24;">' . $reason . '</td>'
                    . '</tr>';
            }
            echo '</table>';
            echo '</div></details>';
        }

        // ── Empty state ───────────────────────────────────────────────────────
        if ( empty( $successful ) && empty( $rejected ) && empty( $external ) ) {
            echo '<p style="margin-top:8px;font-size:12px;color:#666;font-style:italic;">';
            echo esc_html__( 'No platform candidates were found in this research run.', 'tmwseo' );
            echo '</p>';
        }
    }

    /**
     * Classify an external candidate URL into a platform group.
     * Used when the candidate is not in PlatformRegistry (e.g. OnlyFans, TikTok).
     *
     * @param array<string,mixed> $ec  External candidate row.
     * @return string  'social'|'fansite'|'cam'|'linkhub'|'other'
     */
    private static function classify_external_candidate( array $ec ): string {
        $url = strtolower( (string) ( $ec['url'] ?? '' ) );

        // Social networks
        foreach ( [ 'twitter.com', 'x.com', 'tiktok.com', 'facebook.com', 'instagram.com',
                    'youtube.com', 'snapchat.com', 'reddit.com', 'pinterest.com' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'social'; }
        }
        // Tube sites (video hosting, NOT cam platforms)
        foreach ( [ 'pornhub.com', 'xvideos.com', 'xnxx.com', 'xhamster.com',
                    'redtube.com', 'tube8.com', 'youporn.com', 'spankbang.com',
                    'eporner.com', 'tnaflix.com', 'drtuber.com' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'tube'; }
        }
        // Fansites / subscription platforms
        foreach ( [ 'onlyfans.com', 'fansly.com', 'fancentro.com', 'manyvids.com',
                    'loyalfans.com', 'ifans.com', 'admireme.vip', 'justfor.fans',
                    'clips4sale.com', 'patreon.com', 'modelcentro.com' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'fansite'; }
        }
        // Cam platforms
        foreach ( [ 'chaturbate.com', 'stripchat.com', 'livejasmin.com', 'myfreecams.com',
                    'camsoda.com', 'bongacams.com', 'cam4.com', 'imlive.com', 'streamate.com',
                    'flirt4free.com', 'jerkmate', 'cams.com', 'sinparty.com', 'xtease.com',
                    'olecams.com', 'cameraprive.com', 'camirada.com', 'sakuralive.com',
                    'xcams.com', 'xlovecam.com' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'cam'; }
        }
        // Link hubs
        foreach ( [ 'linktr.ee', 'allmylinks.com', 'beacons.ai', 'solo.to', '.carrd.co' ] as $h ) {
            if ( str_contains( $url, $h ) ) { return 'linkhub'; }
        }
        return 'other';
    }

    /**
     * Map a platform slug to the nearest VerifiedLinks ALLOWED_TYPES value.
     * Used to pre-fill the type on per-row promote buttons in the trusted table.
     * The operator sees and can override this value before submitting.
     */
    private static function platform_slug_to_vl_type( string $slug ): string {
        $map = [
            'twitter'    => 'x',
            'fansly'     => 'fansly',
            'fancentro'  => 'fancentro',
            'streamate'  => 'streamate',
            'linktree'   => 'linktree',
            'allmylinks' => 'linktree',
            'beacons'    => 'linktree',
            'solo_to'    => 'linktree',
            'carrd'      => 'personal_site',
        ];
        return $map[ $slug ] ?? 'other';
    }

    // ── Block-editor fallback: Gutenberg asset + AJAX save ──────────────

    /**
     * Enqueue the block-editor JS that persists Model Research fields via AJAX
     * when Gutenberg saves without submitting classic metabox POST data.
     * Mirrors the pattern used by PlatformProfiles::enqueue_editor_assets().
     */
    public static function enqueue_editor_assets(): void {
        if ( ! function_exists( 'get_current_screen' ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ( $screen->base ?? '' ) !== 'post' ) { return; }
        if ( ( $screen->post_type ?? '' ) !== 'model' ) { return; }

        wp_enqueue_script(
            'tmwseo-model-research-editor',
            TMWSEO_ENGINE_URL . 'assets/js/model-research-editor.js',
            [ 'wp-data' ],
            TMWSEO_ENGINE_VERSION,
            true
        );

        wp_localize_script( 'tmwseo-model-research-editor', 'TMWSEOModelResearch', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'tmwseo_model_research_ajax' ),
        ] );
    }

    /** Enqueue the isolated public-profile import control for model editors. */
    public static function enqueue_public_profile_import_assets(): void {
        if ( ! function_exists( 'get_current_screen' ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ( $screen->base ?? '' ) !== 'post' || ( $screen->post_type ?? '' ) !== 'model' ) { return; }

        wp_enqueue_script(
            'tmwseo-public-profile-import',
            TMWSEO_ENGINE_URL . 'assets/js/public-profile-import.js',
            [],
            TMWSEO_ENGINE_VERSION,
            true
        );
        wp_localize_script( 'tmwseo-public-profile-import', 'TMWSEOPublicProfileImport', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    /**
     * AJAX handler: persist Model Research fields from the block editor.
     *
     * Called by model-research-editor.js on every manual Gutenberg save.
     * Uses identical sanitization to save_metabox() so data is always clean
     * regardless of which save path wrote it.
     *
     * Does NOT change research status unless the existing logic in save_metabox
     * already does so (status promotion when moving from 'not_researched' with data).
     */
    public static function ajax_save_model_research(): void {
        check_ajax_referer( 'tmwseo_model_research_ajax' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => 'Missing post_id' ], 400 );
        }

        if ( get_post_type( $post_id ) !== 'model' ) {
            wp_send_json_error( [ 'message' => 'Invalid post type' ], 400 );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        // ── Scalar fields (sanitize_text_field) ──────────────────────────────
        $scalar_map = [
            'display_name'   => ModelHelper::META_DISPLAY_NAME,
            'aliases'        => ModelHelper::META_ALIASES,
            'platform_names' => ModelHelper::META_PLATFORMS,
            'country'        => ModelHelper::META_COUNTRY,
            'language'       => ModelHelper::META_LANGUAGE,
            'editor_seed_tags' => ModelHelper::META_EDITOR_SEED_TAGS,
            'editor_seed_tone_hint' => ModelHelper::META_EDITOR_SEED_TONE_HINT,
        ];
        foreach ( $scalar_map as $key => $meta_key ) {
            $val = isset( $_POST[ $key ] )
                ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) )
                : '';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // ── Textarea fields (sanitize_textarea_field) ─────────────────────────
        foreach ( [
            'bio'   => ModelHelper::META_BIO,
            'notes' => ModelHelper::META_NOTES,
            'editor_seed_summary' => ModelHelper::META_EDITOR_SEED_SUMMARY,
            'editor_seed_platform_notes' => ModelHelper::META_EDITOR_SEED_PLATFORM_NOTES,
            'editor_seed_confirmed_facts' => ModelHelper::META_EDITOR_SEED_CONFIRMED_FACTS,
            'editor_seed_avoid_claims' => ModelHelper::META_EDITOR_SEED_AVOID_CLAIMS,
            // v5.8.7: 3 simple Model Research evidence textareas — block-editor parity.
            'seed_external_bio'           => ModelHelper::META_SEED_EXTERNAL_BIO,
            'seed_external_turn_ons'      => ModelHelper::META_SEED_EXTERNAL_TURN_ONS,
            'seed_external_private_chat'  => ModelHelper::META_SEED_EXTERNAL_PRIVATE_CHAT,
        ] as $key => $meta_key ) {
            $val = isset( $_POST[ $key ] )
                ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) )
                : '';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // ── URL list fields (one per line → JSON array) ───────────────────────
        foreach ( [
            'social_urls'  => ModelHelper::META_SOCIAL_URLS,
            'source_urls'  => ModelHelper::META_SOURCE_URLS,
        ] as $key => $meta_key ) {
            $raw  = isset( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';
            $urls = ModelHelper::sanitize_url_list( $raw );
            update_post_meta( $post_id, $meta_key, wp_json_encode( $urls ) );
        }

        // ── Confidence: integer 0-100 ─────────────────────────────────────────
        $confidence = isset( $_POST['confidence'] )
            ? max( 0, min( 100, (int) $_POST['confidence'] ) )
            : 0;
        update_post_meta( $post_id, ModelHelper::META_CONFIDENCE, $confidence );

        // ── Status promotion (mirrors save_metabox logic exactly) ─────────────
        $current_status = (string) get_post_meta( $post_id, ModelHelper::META_STATUS, true );
        if ( $current_status === '' || $current_status === 'not_researched' ) {
            $aliases_val = isset( $_POST['aliases'] ) ? trim( (string) $_POST['aliases'] ) : '';
            $bio_val     = isset( $_POST['bio'] )     ? trim( (string) $_POST['bio'] )     : '';
            $seed_val    = isset( $_POST['editor_seed_summary'] ) ? trim( (string) $_POST['editor_seed_summary'] ) : '';
            if ( $confidence > 0 || $bio_val !== '' || $aliases_val !== '' || $seed_val !== '' ) {
                update_post_meta( $post_id, ModelHelper::META_STATUS, 'researched' );
                update_post_meta( $post_id, ModelHelper::META_LAST_AT, current_time( 'mysql' ) );
            }
        }

        Logs::info( 'model_research', '[TMW-RESEARCH] ajax_save_model_research: fields saved via block-editor fallback', [
            'post_id' => $post_id,
        ] );

        wp_send_json_success( [ 'saved' => true ] );
    }

    // ── Metabox: save ────────────────────────────────────────────────────

    public static function save( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['tmwseo_model_research_nonce'] ) ) { return; }
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( (string) $_POST['tmwseo_model_research_nonce'] ) ),
            'tmwseo_model_research_save_' . $post_id
        ) ) { return; }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        $scalar_map = [
            'tmwseo_research_display_name'   => ModelHelper::META_DISPLAY_NAME,
            'tmwseo_research_aliases'         => ModelHelper::META_ALIASES,
            'tmwseo_research_platform_names'  => ModelHelper::META_PLATFORMS,
            'tmwseo_research_country'         => ModelHelper::META_COUNTRY,
            'tmwseo_research_language'        => ModelHelper::META_LANGUAGE,
            'tmwseo_editor_seed_tags'         => ModelHelper::META_EDITOR_SEED_TAGS,
            'tmwseo_editor_seed_tone_hint'    => ModelHelper::META_EDITOR_SEED_TONE_HINT,
        ];

        foreach ( $scalar_map as $post_key => $meta_key ) {
            $val = isset( $_POST[ $post_key ] )
                ? sanitize_text_field( wp_unslash( (string) $_POST[ $post_key ] ) )
                : '';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // Bio and Notes — allow multi-line, sanitize as text area
        foreach ( [
            'tmwseo_research_bio'   => ModelHelper::META_BIO,
            'tmwseo_research_notes' => ModelHelper::META_NOTES,
            'tmwseo_editor_seed_summary' => ModelHelper::META_EDITOR_SEED_SUMMARY,
            'tmwseo_editor_seed_platform_notes' => ModelHelper::META_EDITOR_SEED_PLATFORM_NOTES,
            'tmwseo_editor_seed_confirmed_facts' => ModelHelper::META_EDITOR_SEED_CONFIRMED_FACTS,
            'tmwseo_editor_seed_avoid_claims' => ModelHelper::META_EDITOR_SEED_AVOID_CLAIMS,
            // Bio evidence textarea fields
            'tmwseo_bio_summary'      => ModelHelper::META_BIO_SUMMARY,
            'tmwseo_bio_source_facts' => ModelHelper::META_BIO_SOURCE_FACTS,
            // Model Research external evidence (v5.8.7) — 3 simple textareas
            'tmwseo_seed_external_bio'           => ModelHelper::META_SEED_EXTERNAL_BIO,
            'tmwseo_seed_external_turn_ons'      => ModelHelper::META_SEED_EXTERNAL_TURN_ONS,
            'tmwseo_seed_external_private_chat'  => ModelHelper::META_SEED_EXTERNAL_PRIVATE_CHAT,
        ] as $post_key => $meta_key ) {
            $val = isset( $_POST[ $post_key ] )
                ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $post_key ] ) )
                : '';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // Bio evidence — text / select / URL fields
        foreach ( [
            'tmwseo_bio_source_type'   => ModelHelper::META_BIO_SOURCE_TYPE,
            'tmwseo_bio_review_status' => ModelHelper::META_BIO_REVIEW_STATUS,
            'tmwseo_bio_reviewed_at'   => ModelHelper::META_BIO_REVIEWED_AT,
            'tmwseo_bio_source_label'  => ModelHelper::META_BIO_SOURCE_LABEL,
            'tmwseo_bio_source_url'    => ModelHelper::META_BIO_SOURCE_URL,
        ] as $post_key => $meta_key ) {
            $val = isset( $_POST[ $post_key ] )
                ? sanitize_text_field( wp_unslash( (string) $_POST[ $post_key ] ) )
                : '';
            // Allowlist for review status to prevent arbitrary values.
            if ( $post_key === 'tmwseo_bio_review_status' && ! in_array( $val, [ '', 'draft', 'reviewed' ], true ) ) {
                $val = '';
            }
            // Allowlist for source type.
            if ( $post_key === 'tmwseo_bio_source_type' && ! in_array( $val, [ '', 'editor', 'platform_page', 'press', 'wps_import', 'none' ], true ) ) {
                $val = '';
            }
            // Validate URL field.
            if ( $post_key === 'tmwseo_bio_source_url' && $val !== '' ) {
                $val = esc_url_raw( $val );
            }
            update_post_meta( $post_id, $meta_key, $val );
        }

        // External profile evidence text/URL save block REMOVED in v5.8.7 —
        // the simple 3-field flow uses sanitize_textarea_field on the 3
        // META_SEED_EXTERNAL_* keys above. No source URL, review status,
        // reviewed_at, or reviewer notes meta to persist.

        // URL fields — one per line → JSON array
        foreach ( [
            'tmwseo_research_social_urls'  => ModelHelper::META_SOCIAL_URLS,
            'tmwseo_research_source_urls'  => ModelHelper::META_SOURCE_URLS,
        ] as $post_key => $meta_key ) {
            $raw  = isset( $_POST[ $post_key ] ) ? wp_unslash( (string) $_POST[ $post_key ] ) : '';
            $urls = ModelHelper::sanitize_url_list( $raw );
            update_post_meta( $post_id, $meta_key, wp_json_encode( $urls ) );
        }

        // Confidence: integer 0-100
        $confidence = isset( $_POST['tmwseo_research_confidence'] )
            ? max( 0, min( 100, (int) $_POST['tmwseo_research_confidence'] ) )
            : 0;
        update_post_meta( $post_id, ModelHelper::META_CONFIDENCE, $confidence );

        // Status: if currently 'not_researched' or '' and data was saved, mark as 'researched'
        $current_status = (string) get_post_meta( $post_id, ModelHelper::META_STATUS, true );
        if ( $current_status === '' || $current_status === 'not_researched' ) {
            $has_data = $confidence > 0
                || ( isset( $_POST['tmwseo_research_bio'] ) && trim( (string) $_POST['tmwseo_research_bio'] ) !== '' )
                || ( isset( $_POST['tmwseo_research_aliases'] ) && trim( (string) $_POST['tmwseo_research_aliases'] ) !== '' )
                || ( isset( $_POST['tmwseo_editor_seed_summary'] ) && trim( (string) $_POST['tmwseo_editor_seed_summary'] ) !== '' );
            if ( $has_data ) {
                update_post_meta( $post_id, ModelHelper::META_STATUS, 'researched' );
                update_post_meta( $post_id, ModelHelper::META_LAST_AT, current_time( 'mysql' ) );
            }
        }
    }

    // ── List screen: columns ──────────────────────────────────────────────

    /** @param array<string,string> $columns */
}
