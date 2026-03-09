<?php
/**
 * TMW SEO Engine — Shared Admin UI helpers
 *
 * Presentation-only. No data, no logic, no AJAX, no handlers.
 * Provides shared CSS and shell helpers used by all plugin admin pages.
 * Command Center keeps its own tmwcc- prefix; this layer adds tmwui- on top.
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.3.0
 */
namespace TMWSEO\Engine\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AdminUI {

	// ── Boot ────────────────────────────────────────────────────────────

	/**
	 * Attach the shared admin stylesheet.
	 * Piggy-backs on the always-present 'wp-admin' handle so CSS is guaranteed
	 * to output on every plugin admin page with no hook-order edge-cases.
	 * Safe to call multiple times — guarded by a static flag.
	 */
	public static function enqueue(): void {
		static $done = false;
		if ( $done ) { return; }
		$done = true;
		wp_add_inline_style( 'wp-admin', self::css() );
	}

	// ── Page shell helpers ───────────────────────────────────────────────

	/**
	 * Render the standard page header block.
	 *
	 * Outputs the <h1> title, an optional subtitle line, and an optional
	 * compact trust badge. Does NOT open or close <div class="wrap"> —
	 * the caller manages the outer wrapper.
	 *
	 * @param string $title      Page title (plain text, will be escaped).
	 * @param string $subtitle   Optional one-liner shown in muted grey below title.
	 * @param bool   $trust_safe When true appends "Manual-only · Draft-only · No auto-publish" badge.
	 */
	public static function page_header(
		string $title,
		string $subtitle = '',
		bool $trust_safe = true
	): void {
		echo '<div class="tmwui-header">';
		echo '<h1 class="tmwui-title">' . esc_html( $title ) . '</h1>';
		if ( $subtitle !== '' ) {
			echo '<p class="tmwui-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		if ( $trust_safe ) {
			echo '<span class="tmwui-trust-badge">&#128274; Manual-only &middot; Draft-only &middot; No auto-publish</span>';
		}
		echo '</div>';
	}

	/**
	 * Open a named section block.
	 * Must be paired with a matching section_end() call.
	 *
	 * @param string $title Section heading (plain text).
	 * @param string $sub   Optional sub-heading shown below the title line.
	 */
	public static function section_start( string $title, string $sub = '' ): void {
		echo '<section class="tmwui-section">';
		echo '<h2 class="tmwui-section-title">' . esc_html( $title ) . '</h2>';
		if ( $sub !== '' ) {
			echo '<p class="tmwui-section-sub">' . esc_html( $sub ) . '</p>';
		}
	}

	/** Close a section block opened with section_start(). */
	public static function section_end(): void {
		echo '</section>';
	}

	/**
	 * Render a horizontal row of KPI stat cards.
	 *
	 * @param array $cards {
	 *   @type int|string $value  Numeric or short string displayed large.
	 *   @type string     $label  Card label below the value.
	 *   @type string     $color  One of: ok, warn, danger, neutral. Defaults to neutral.
	 *   @type string     $url    Optional. When set the card renders as an <a>.
	 *   @type string     $sub    Optional. Small secondary line below the label.
	 * }
	 */
	public static function kpi_row( array $cards ): void {
		echo '<div class="tmwui-kpi-row">';
		foreach ( $cards as $card ) {
			$allowed = [ 'ok', 'warn', 'danger', 'neutral' ];
			$color   = in_array( $card['color'] ?? '', $allowed, true ) ? $card['color'] : 'neutral';
			$url     = (string) ( $card['url'] ?? '' );
			$tag     = $url !== '' ? 'a' : 'div';
			$href    = $url !== '' ? ' href="' . esc_url( $url ) . '"' : '';
			echo '<' . $tag . ' class="tmwui-kpi tmwui-kpi-' . esc_attr( $color ) . '"' . $href . '>';
			echo '<span class="tmwui-kpi-value">' . esc_html( (string) ( $card['value'] ?? '—' ) ) . '</span>';
			echo '<span class="tmwui-kpi-label">' . esc_html( (string) ( $card['label'] ?? '' ) ) . '</span>';
			if ( ! empty( $card['sub'] ) ) {
				echo '<span class="tmwui-kpi-sub">' . esc_html( (string) $card['sub'] ) . '</span>';
			}
			echo '</' . $tag . '>';
		}
		echo '</div>';
	}

	/**
	 * Render a grid of tool or content cards.
	 *
	 * Each card has a title, optional description paragraph, and an optional
	 * action area. action_html must be pre-escaped by the caller — it is
	 * output with echo, not esc_html.
	 *
	 * @param array $cards {
	 *   @type string $title       Card heading (plain text).
	 *   @type string $desc        Optional description paragraph (plain text).
	 *   @type string $action_html Optional pre-escaped HTML for the card action area.
	 * }
	 */
	public static function card_grid( array $cards ): void {
		echo '<div class="tmwui-card-grid">';
		foreach ( $cards as $card ) {
			echo '<div class="tmwui-card">';
			echo '<h3 class="tmwui-card-title">' . esc_html( (string) ( $card['title'] ?? '' ) ) . '</h3>';
			if ( ! empty( $card['desc'] ) ) {
				echo '<p class="tmwui-card-desc">' . esc_html( (string) $card['desc'] ) . '</p>';
			}
			if ( ! empty( $card['action_html'] ) ) {
				echo '<div class="tmwui-card-action">' . $card['action_html'] . '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render a compact empty-state block.
	 *
	 * @param string $message   Plain-text message.
	 * @param string $cta_html  Optional pre-escaped CTA HTML placed below the message.
	 */
	public static function empty_state( string $message, string $cta_html = '' ): void {
		echo '<div class="tmwui-empty">';
		echo '<p class="tmwui-empty-msg">' . esc_html( $message ) . '</p>';
		if ( $cta_html !== '' ) {
			echo $cta_html;
		}
		echo '</div>';
	}

	/**
	 * Render a quiet trust reminder (border-left style, not .notice).
	 *
	 * @param string $msg Custom message. Falls back to standard manual-only reminder.
	 */
	public static function trust_reminder( string $msg = '' ): void {
		$text = $msg !== ''
			? $msg
			: 'Manual-only mode is always enforced. Nothing publishes automatically and every action requires explicit approval.';
		echo '<p class="tmwui-trust">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Render an inline alert banner.
	 *
	 * @param string $msg   Plain-text message.
	 * @param string $level One of: info, warn, danger. Defaults to info.
	 */
	public static function alert( string $msg, string $level = 'info' ): void {
		$allowed = [ 'info', 'warn', 'danger' ];
		$level   = in_array( $level, $allowed, true ) ? $level : 'info';
		echo '<div class="tmwui-alert tmwui-alert-' . esc_attr( $level ) . '">' . esc_html( $msg ) . '</div>';
	}

	// ── CSS ──────────────────────────────────────────────────────────────

	/**
	 * Returns the shared admin CSS string.
	 * All rules are namespaced under .tmwui- to avoid collisions.
	 */
	public static function css(): string {
		return '
/* ═══════════════════════════════════════════════════════════
   TMW SEO Engine — Shared Admin Design System
   Prefix: tmwui-   (Command Center keeps its own tmwcc-)
   Single source of truth for plugin admin pages.
   Edit here only — never add one-off inline styles per page.
═══════════════════════════════════════════════════════════ */

/* ── Page header block ───────────────────────────────────── */
.tmwui-header {
    margin-bottom: 24px;
}
.tmwui-title {
    font-size: 22px !important;
    font-weight: 700 !important;
    margin: 0 0 4px !important;
    line-height: 1.2;
    color: #1e1e1e;
}
.tmwui-subtitle {
    font-size: 13px;
    color: #6b7280;
    margin: 0 0 10px !important;
    line-height: 1.5;
}
.tmwui-trust-badge {
    display: inline-block;
    font-size: 11px;
    color: #15803d;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    padding: 2px 10px;
    border-radius: 999px;
    font-weight: 600;
    letter-spacing: 0.01em;
}

/* ── Section chrome ──────────────────────────────────────── */
.tmwui-section {
    margin-bottom: 32px;
}
.tmwui-section-title {
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #111827 !important;
    margin: 0 0 8px !important;
    padding-bottom: 8px;
    border-bottom: 2px solid #e5e7eb;
}
.tmwui-section-sub {
    font-size: 12px;
    color: #6b7280;
    margin: 0 0 14px !important;
    line-height: 1.5;
}

/* ── KPI cards ───────────────────────────────────────────── */
.tmwui-kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
    gap: 14px;
    margin-bottom: 16px;
}
.tmwui-kpi {
    display: flex;
    flex-direction: column;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-top-width: 3px;
    border-radius: 10px;
    padding: 16px;
    text-decoration: none;
    color: #111827;
    transition: box-shadow 0.15s;
}
a.tmwui-kpi:hover {
    box-shadow: 0 4px 14px rgba(0,0,0,0.09);
    color: #111827;
}
.tmwui-kpi-ok      { border-top-color: #16a34a; }
.tmwui-kpi-ok      .tmwui-kpi-value { color: #15803d; }
.tmwui-kpi-warn    { border-top-color: #eab308; }
.tmwui-kpi-warn    .tmwui-kpi-value { color: #92400e; }
.tmwui-kpi-danger  { border-top-color: #dc2626; }
.tmwui-kpi-danger  .tmwui-kpi-value { color: #991b1b; }
.tmwui-kpi-neutral { border-top-color: #6366f1; }
.tmwui-kpi-neutral .tmwui-kpi-value { color: #4338ca; }
.tmwui-kpi-value   { font-size: 28px; font-weight: 700; line-height: 1.1; margin-bottom: 4px; }
.tmwui-kpi-label   { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 2px; }
.tmwui-kpi-sub     { font-size: 11px; color: #9ca3af; }

/* ── Tool / content cards ────────────────────────────────── */
.tmwui-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 8px;
}
.tmwui-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px;
    display: flex;
    flex-direction: column;
}
.tmwui-card-title {
    font-size: 14px !important;
    font-weight: 700 !important;
    margin: 0 0 8px !important;
    color: #111827;
}
.tmwui-card-desc {
    font-size: 13px;
    color: #4b5563;
    margin: 0 0 14px !important;
    line-height: 1.5;
    flex-grow: 1;
}
.tmwui-card-action {
    margin-top: auto;
}

/* ── Alert banners ───────────────────────────────────────── */
.tmwui-alert {
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    border: 1px solid transparent;
    margin-bottom: 16px;
    line-height: 1.5;
}
.tmwui-alert-info   { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
.tmwui-alert-warn   { background: #fefce8; border-color: #fde68a; color: #78350f; }
.tmwui-alert-danger { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }

/* ── Quiet trust reminder ────────────────────────────────── */
.tmwui-trust {
    font-size: 12px;
    color: #6b7280;
    border-left: 3px solid #d1d5db;
    padding: 4px 10px;
    margin: 0 0 16px !important;
    background: #f9fafb;
    line-height: 1.5;
}

/* ── Empty state ─────────────────────────────────────────── */
.tmwui-empty {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
    border: 1px dashed #d1d5db;
    border-radius: 10px;
    background: #fafafa;
    margin-bottom: 24px;
}
.tmwui-empty-msg {
    font-size: 14px;
    margin-bottom: 14px !important;
}

/* ── CTA / action row ────────────────────────────────────── */
.tmwui-cta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
    align-items: center;
}

/* ── Filter bar ──────────────────────────────────────────── */
.tmwui-filter-bar {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 12px;
}
/* subsubsub inside filter bar — keep flex, add row gap */
.tmwui-filter-bar .subsubsub {
    margin: 0 0 8px !important;
    float: none !important;
    display: flex !important;
    flex-wrap: wrap;
    gap: 4px;
}
.tmwui-filter-bar > p.description {
    font-size: 11px !important;
    color: #64748b;
    margin: 4px 0 0 !important;
    padding: 0;
    border: none;
    background: none;
    display: block;
}

/* ── Compact table wrapper ───────────────────────────────── */
.tmwui-table-wrap {
    overflow-x: auto;
    margin-bottom: 24px;
}
.tmwui-table-wrap table.widefat td,
.tmwui-table-wrap table.widefat th {
    font-size: 13px;
    padding: 8px 10px;
}

/* ── Collapsible advanced block ──────────────────────────── */
.tmwui-advanced {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin-bottom: 12px;
    background: #fafafa;
}
.tmwui-advanced > summary {
    padding: 10px 14px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    color: #6b7280;
    list-style: none;
    user-select: none;
}
.tmwui-advanced > summary::-webkit-details-marker { display: none; }
.tmwui-advanced > summary::before { content: "\25B6  "; font-size: 9px; }
.tmwui-advanced[open] > summary::before { content: "\25BC  "; }
.tmwui-advanced[open] > summary {
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
}
.tmwui-advanced-body {
    padding: 14px;
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 782px) {
    .tmwui-kpi-row      { grid-template-columns: repeat(2, 1fr); }
    .tmwui-card-grid    { grid-template-columns: 1fr; }
}
@media (max-width: 480px) {
    .tmwui-kpi-row      { grid-template-columns: 1fr; }
}

/* ════════════════════════════════════════════════════════════════
   GLOBAL PLUGIN PAGE HARMONIZATION
   Applied only to TMW SEO Engine admin hooks. Uses .wrap as outer
   scope. Excludes .td-wrap (Command Center / Reports / Connections)
   which have their own design system.
   ════════════════════════════════════════════════════════════════ */

/* ── Container & font ────────────────────────────────────── */
.wrap {
    font-family: "DM Sans","Inter",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    max-width: 1340px;
}

/* ── Page-level H1 ───────────────────────────────────────── */
.wrap:not(.td-wrap) > h1,
.wrap:not(.td-wrap) > h1.wp-heading-inline {
    font-size: 21px !important;
    font-weight: 800 !important;
    color: #0f172a;
    line-height: 1.25;
    margin: 6px 0 20px !important;
    padding-bottom: 16px;
    border-bottom: 2px solid #e2e8f0;
}

/* ── Section H2 headings ─────────────────────────────────── */
.wrap:not(.td-wrap) h2:not(.tmwui-section-title) {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: #1e293b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 28px 0 14px !important;
    padding: 9px 14px 9px 16px;
    background: #f8fafc;
    border-left: 3px solid #2563eb;
    border-radius: 0 6px 6px 0;
}

/* ── widefat / striped tables ────────────────────────────── */
.wrap table.widefat {
    border-radius: 10px;
    border: 1.5px solid #e2e8f0 !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
    box-shadow: 0 2px 10px rgba(0,0,0,0.055);
    overflow: hidden;
    background: #fff;
    margin-top: 6px;
}

.wrap table.widefat thead th,
.wrap table.widefat thead td {
    background: #f8fafc !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b !important;
    padding: 10px 14px !important;
    border-bottom: 1.5px solid #e2e8f0 !important;
    white-space: nowrap;
}

.wrap table.widefat tbody td {
    font-size: 13px;
    padding: 10px 14px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    color: #1e293b;
    vertical-align: top;
}

.wrap table.widefat.striped tbody > tr:nth-child(odd) td {
    background: #fafbfc !important;
}

.wrap table.widefat tbody tr:hover td {
    background: #f0f7ff !important;
}

.wrap table.widefat tbody tr:last-child td {
    border-bottom: none !important;
}

/* Keep th/td in key-value tbody tables readable */
.wrap table.widefat tbody th {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #374151 !important;
    background: #f8fafc !important;
    padding: 10px 14px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    border-right: 1px solid #f1f5f9 !important;
    vertical-align: middle;
    white-space: nowrap;
}

/* ── Settings form-table ─────────────────────────────────── */
.wrap .form-table {
    background: #fff;
    border: 1.5px solid #e2e8f0 !important;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.055);
    margin-bottom: 20px !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
    width: 100%;
}

.wrap .form-table th {
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    background: #f8fafc !important;
    padding: 14px 20px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    border-right: 1px solid #f1f5f9 !important;
    width: 220px;
    vertical-align: top;
}

.wrap .form-table td {
    padding: 12px 20px !important;
    border-bottom: 1px solid #f1f5f9 !important;
    vertical-align: top;
}

.wrap .form-table tr:last-child th,
.wrap .form-table tr:last-child td {
    border-bottom: none !important;
}

.wrap .form-table .description {
    font-size: 12px !important;
    color: #64748b;
    margin-top: 5px !important;
    line-height: 1.5;
}

/* ── Settings: inputs, selects, textareas ────────────────── */
.wrap .form-table input[type="text"],
.wrap .form-table input[type="password"],
.wrap .form-table input[type="number"],
.wrap .form-table input[type="email"],
.wrap .form-table select,
.wrap .form-table textarea.large-text,
.wrap .form-table textarea.code {
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 7px !important;
    padding: 7px 10px !important;
    font-size: 13px !important;
    font-family: inherit;
    background: #fff;
    color: #1e293b;
    transition: border-color .15s, box-shadow .15s;
    box-shadow: none;
}

.wrap .form-table input[type="text"]:focus,
.wrap .form-table input[type="password"]:focus,
.wrap .form-table input[type="number"]:focus,
.wrap .form-table select:focus,
.wrap .form-table textarea:focus {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important;
    outline: none !important;
}

/* ── Notice blocks ───────────────────────────────────────── */
.wrap:not(.td-wrap) .notice,
.tmwseo-suggestions-page .notice {
    border-radius: 8px !important;
    border-left-width: 4px !important;
    margin: 4px 0 18px !important;
    padding: 10px 16px !important;
    font-size: 13px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}

/* ── Inline safety / warning banners ────────────────────── */
.wrap:not(.td-wrap) > div[style*="background:#fefce8"],
.wrap:not(.td-wrap) > div[style*="background:#dcfce7"],
.wrap:not(.td-wrap) > div[style*="background:#f8f9fa"] {
    border-radius: 8px;
    padding: 12px 16px !important;
    margin-bottom: 16px !important;
    font-size: 13px;
}

/* ── subsubsub filter pill navigation ────────────────────── */
.wrap .subsubsub {
    float: none !important;
    margin: 0 0 16px !important;
    display: flex !important;
    flex-wrap: wrap;
    gap: 4px;
    align-items: center;
    padding: 0 !important;
    list-style: none !important;
    /* hide the raw | text separators between <li> elements */
    font-size: 0;
}

.wrap .subsubsub li {
    display: inline-flex !important;
    align-items: center;
    margin: 0 !important;
    padding: 0 !important;
    float: none !important;
    font-size: 13px;
}

.wrap .subsubsub li a,
.wrap .subsubsub li span.count {
    display: inline-block;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-decoration: none;
    background: #f1f5f9;
    border: 1.5px solid #e2e8f0;
    border-radius: 99px;
    transition: all .15s;
    white-space: nowrap;
}

.wrap .subsubsub li a:hover {
    background: #eff6ff;
    color: #2563eb;
    border-color: #bfdbfe;
    text-decoration: none;
}

.wrap .subsubsub .current,
.wrap .subsubsub li a.current {
    background: #eff6ff !important;
    color: #2563eb !important;
    border-color: #2563eb !important;
}

.wrap .subsubsub .sep {
    display: none !important;
}

/* ── Suggestions table: responsive & readable ─────────────── */
/* Override widefat fixed layout for suggestions (too many cols) */
.tmwseo-suggestions-table {
    table-layout: auto !important;
    width: 100% !important;
    overflow-x: auto !important;
    display: block !important;
}

.tmwseo-suggestions-table thead,
.tmwseo-suggestions-table tbody {
    display: table;
    width: 100%;
    table-layout: auto;
}

/* Column widths: keep tight cols tight, give description room */
.tmwseo-suggestions-table th:nth-child(1),
.tmwseo-suggestions-table td:nth-child(1) { width: 100px; min-width: 100px; }  /* Priority    */
.tmwseo-suggestions-table th:nth-child(2),
.tmwseo-suggestions-table td:nth-child(2) { width: 130px; min-width: 120px; }  /* Status      */
.tmwseo-suggestions-table th:nth-child(3),
.tmwseo-suggestions-table td:nth-child(3) { width: 110px; min-width: 100px; }  /* Type        */
.tmwseo-suggestions-table th:nth-child(4),
.tmwseo-suggestions-table td:nth-child(4) { width: 130px; min-width: 120px; }  /* Draft Target */
.tmwseo-suggestions-table th:nth-child(5),
.tmwseo-suggestions-table td:nth-child(5) { width: 130px; min-width: 120px; }  /* Action      */
.tmwseo-suggestions-table th:nth-child(6),
.tmwseo-suggestions-table td:nth-child(6) { width: 140px; min-width: 130px; }  /* Title       */
.tmwseo-suggestions-table th:nth-child(7),
.tmwseo-suggestions-table td:nth-child(7) { width: 240px; min-width: 200px; max-width: 280px; word-break: break-word; overflow-wrap: break-word; } /* Description */
.tmwseo-suggestions-table th:nth-child(8),
.tmwseo-suggestions-table td:nth-child(8) { width: 70px;  min-width: 60px;  text-align: right; } /* Est. Traffic */
.tmwseo-suggestions-table th:nth-child(9),
.tmwseo-suggestions-table td:nth-child(9) { width: 65px;  min-width: 55px;  text-align: right; } /* Difficulty  */
.tmwseo-suggestions-table th:nth-child(10),
.tmwseo-suggestions-table td:nth-child(10) { width: 110px; min-width: 100px; } /* Source Engine */
.tmwseo-suggestions-table th:nth-child(11),
.tmwseo-suggestions-table td:nth-child(11) { width: 100px; min-width: 90px; }  /* Date        */
.tmwseo-suggestions-table th:nth-child(12),
.tmwseo-suggestions-table td:nth-child(12) { width: 100px; min-width: 90px; }  /* Aging       */
.tmwseo-suggestions-table th:nth-child(13),
.tmwseo-suggestions-table td:nth-child(13) { width: 200px; min-width: 180px; white-space: nowrap; } /* Actions */

/* Action buttons column: stack vertically */
.tmwseo-suggestions-table td:nth-child(13) .button,
.tmwseo-suggestions-table td:nth-child(13) input[type="submit"] {
    display: block;
    width: 100%;
    margin: 0 0 5px 0 !important;
    text-align: center;
    box-sizing: border-box;
    white-space: normal;
}

.tmwseo-suggestions-table td:nth-child(13) form {
    display: block !important;
    margin: 0 0 5px 0 !important;
}

/* Truncate overflowing inline-preview details */
.tmwseo-inline-preview {
    max-width: 260px;
    word-break: break-word;
    overflow-wrap: break-word;
}

/* Make the description block wrap properly */
.tmwseo-description-block {
    word-break: break-word;
    overflow-wrap: break-word;
}

/* Model-focused suggestions page */
.tmwseo-model-focused-wrap table.widefat {
    table-layout: auto !important;
    display: block;
    overflow-x: auto;
}

.tmwseo-model-focused-wrap table.widefat thead,
.tmwseo-model-focused-wrap table.widefat tbody {
    display: table;
    width: 100%;
}

/* ── Queue / Logs: inline filter links in <p> ────────────── */
/* Only target paragraphs that contain links (filter nav bars),
   NOT description/subtitle paragraphs */
.wrap:not(.td-wrap) > p:not(.description):not(.submit) {
    font-size: 13px;
    color: #475569;
    margin: 0 0 10px !important;
}

/* Only pill-style the <p> if it's purely composed of <a> links (filter nav) */
.wrap:not(.td-wrap) > p.tmwseo-filter-links {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
    margin: 0 0 12px !important;
}

.wrap:not(.td-wrap) > p.tmwseo-filter-links > a,
.wrap:not(.td-wrap) > p > a.tmwseo-filter-pill {
    display: inline-flex;
    align-items: center;
    padding: 3px 12px;
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-decoration: none;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 99px;
    transition: all .15s;
    white-space: nowrap;
}

.wrap:not(.td-wrap) > p.tmwseo-filter-links > a:hover,
.wrap:not(.td-wrap) > p > a.tmwseo-filter-pill:hover {
    background: #eff6ff;
    color: #2563eb;
    border-color: #bfdbfe;
    text-decoration: none;
}

.wrap:not(.td-wrap) > p.tmwseo-filter-links > a[style*="font-weight:bold"],
.wrap:not(.td-wrap) > p.tmwseo-filter-links > a[style*="font-weight: bold"] {
    background: #2563eb;
    color: #fff !important;
    border-color: #2563eb;
}

/* ── Competitor Domains: domain list ─────────────────────── */
.wrap:not(.td-wrap) > ul {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 0 !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    list-style: none !important;
    margin: 0 0 20px !important;
    overflow: hidden;
}

.wrap:not(.td-wrap) > ul li {
    padding: 10px 18px;
    font-size: 13px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
}

.wrap:not(.td-wrap) > ul li:last-child {
    border-bottom: none;
}

/* ── Competitor Domains / add-domain inline form strip ───── */
/* Only style compact single-input forms, not full settings forms */
.wrap:not(.td-wrap) > form.tmwseo-inline-form {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin: 0 0 20px !important;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 18px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.wrap:not(.td-wrap) > form.tmwseo-inline-form input[type="text"],
.wrap:not(.td-wrap) > form.tmwseo-inline-form input[type="password"],
.wrap:not(.td-wrap) > form.tmwseo-inline-form input[type="number"] {
    border: 1.5px solid #cbd5e1;
    border-radius: 7px;
    padding: 7px 10px;
    font-size: 13px;
    font-family: inherit;
    background: #fff;
    color: #1e293b;
    flex: 1;
    min-width: 200px;
    transition: border-color .15s, box-shadow .15s;
}

.wrap:not(.td-wrap) > form.tmwseo-inline-form input[type="text"]:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    outline: none;
}

/* ── Keyword Engine Monitor: health bar ──────────────────── */
.wrap:not(.td-wrap) > div[style*="background:#f8f9fa"] {
    border-radius: 10px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    padding: 16px 20px !important;
    margin: 12px 0 20px !important;
    font-size: 13px;
}

/* ── Engine monitor form controls ────────────────────────── */
.wrap:not(.td-wrap) form p .button {
    margin-right: 6px;
}

/* ── pre / code in tables ────────────────────────────────── */
.wrap table.widefat pre {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 8px 10px;
    font-size: 11px;
    color: #334155;
    line-height: 1.5;
    white-space: pre-wrap;
    max-width: 480px;
    overflow: auto;
    margin: 0;
}

.wrap table.widefat code {
    background: #f1f5f9;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 12px;
    color: #475569;
}

/* ── submit_button / form actions ────────────────────────── */
.wrap:not(.td-wrap) .submit {
    padding-left: 0 !important;
    border-top: 1px solid #e2e8f0;
    margin-top: 20px;
    padding-top: 18px;
}

/* ── Keywords page: cluster table ────────────────────────── */
.wrap:not(.td-wrap) > p + div,
.wrap:not(.td-wrap) > p.description {
    font-size: 13px;
    color: #475569;
    margin-bottom: 12px !important;
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 960px) {
    .tmwui-table-wrap { overflow-x: auto; }
    .wrap .form-table th { width: 160px; }
    .tmwseo-suggestions-page .wrap { overflow-x: hidden; }
}

@media (max-width: 782px) {
    .wrap .form-table { display: block; }
    .wrap .form-table th,
    .wrap .form-table td { display: block; width: 100% !important; }
    .wrap .form-table th { border-right: none !important; border-bottom: none !important; padding-bottom: 4px !important; }
    .tmwui-kpi-row { grid-template-columns: repeat(2, 1fr) !important; }
}

/* ════════════════════════════════════════════════════════════════
   SUGGESTIONS DASHBOARD — layout fixes for the main list page
   ════════════════════════════════════════════════════════════════ */

/* Page wrapper */
.tmwseo-suggestions-page {
    max-width: 1340px;
}

/* Header block */
.tmwseo-suggestions-page .tmwui-header {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-left: 4px solid #2563eb;
    border-radius: 10px;
    padding: 18px 22px;
    margin-bottom: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

/* Trust badge inside header */
.tmwseo-suggestions-page .tmwui-trust-badge {
    margin-top: 8px;
    display: inline-block;
}

/* Filter bar — card with stacked pill rows */
.tmwseo-suggestions-page .tmwui-filter-bar {
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 14px;
    box-shadow: 0 1px 6px rgba(0,0,0,0.04);
}

/* Each subsubsub row within filter bar */
.tmwseo-suggestions-page .tmwui-filter-bar .subsubsub {
    display: flex !important;
    flex-wrap: wrap !important;
    float: none !important;
    gap: 4px !important;
    margin: 0 0 8px !important;
    padding: 0 !important;
    list-style: none !important;
    font-size: 0;
}

.tmwseo-suggestions-page .tmwui-filter-bar .subsubsub:last-of-type {
    margin-bottom: 0 !important;
}

.tmwseo-suggestions-page .tmwui-filter-bar .subsubsub li {
    display: inline-flex !important;
    float: none !important;
    margin: 0 !important;
    padding: 0 !important;
    font-size: 13px;
}

/* Active state summary line */
.tmwseo-suggestions-page .tmwui-filter-bar > p.description {
    font-size: 11px !important;
    color: #64748b !important;
    margin: 8px 0 0 !important;
    padding: 6px 10px;
    background: #f8fafc;
    border-radius: 6px;
    border: 1px solid #f1f5f9;
    display: block !important;
}

/* Advanced review filters (details/summary) */
.tmwseo-suggestions-page .tmwui-advanced {
    margin-bottom: 14px;
}

/* Scan action buttons row */
.tmwseo-suggestions-page .tmwui-advanced-body .button {
    margin: 0 6px 6px 0 !important;
}

/* h3 inside advanced body */
.tmwseo-suggestions-page .tmwui-advanced-body h3 {
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #374151 !important;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin: 10px 0 4px !important;
    padding: 0 !important;
    background: none !important;
    border-left: none !important;
}

/* subsubsub inside advanced body */
.tmwseo-suggestions-page .tmwui-advanced-body .subsubsub {
    margin: 0 0 8px !important;
}

/* Category-page pivot links */
.tmwseo-suggestions-page .tmwui-advanced-body > p {
    font-size: 12px;
    margin: 6px 0 !important;
    color: #475569;
    display: block !important;
    background: none !important;
    border: none !important;
    padding: 0 !important;
    border-radius: 0 !important;
}

/* Main table: scrollable */
.tmwseo-suggestions-page .widefat {
    min-width: 900px;
}

.tmwseo-suggestions-page {
    overflow-x: auto;
}

/* KPI row on Suggestions Dashboard */
.tmwseo-suggestions-page .tmwui-kpi-row {
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    margin-bottom: 20px;
}

/* Workflow guide section */
.tmwseo-suggestions-page details.tmwui-advanced:last-of-type {
    margin-bottom: 0;
}

/* ── Content Briefs page ──────────────────────────────────── */
.wrap > h1 + p {
    font-size: 13px;
    color: #475569;
    margin: -10px 0 18px !important;
}

/* ── Debug dashboard ─────────────────────────────────────── */
.wrap .hndle { background: #f8fafc; }

/* ── Intelligence page form ──────────────────────────────── */
.wrap > hr {
    border: none;
    border-top: 1.5px solid #e2e8f0;
    margin: 24px 0;
}

/* ── Opportunity / Debug / Intelligence tables ─────────────── */
.wrap table.widefat tbody td form {
    display: inline-block;
    margin: 0 4px 0 0;
    vertical-align: middle;
}

/* ── All input[type=number] / small-text ──────────────────── */
.wrap input.small-text {
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 6px !important;
    padding: 5px 8px !important;
    font-size: 13px !important;
}

/* ── checkbox labels ─────────────────────────────────────── */
.wrap label > input[type="checkbox"] {
    margin-right: 5px;
}

/* ── Large textarea styling ──────────────────────────────── */
.wrap textarea.large-text {
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 7px !important;
    padding: 8px 12px !important;
    font-size: 13px !important;
    font-family: inherit;
    resize: vertical;
    transition: border-color .15s, box-shadow .15s;
}

.wrap textarea.large-text:focus {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.12) !important;
    outline: none !important;
}

/* ── Debug panels ─────────────────────────────────────────── */
.tmwseo-debug-panel,
.wrap .postbox {
    border: 1.5px solid #e2e8f0 !important;
    border-radius: 10px !important;
    background: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04) !important;
    margin-bottom: 20px;
    overflow: hidden;
}
';
	}
}
