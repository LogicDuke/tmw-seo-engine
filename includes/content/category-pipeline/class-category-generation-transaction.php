<?php
/** Authoritative, rollback-verified category content commit protocol. */
namespace TMWSEO\Engine\Content\CategoryPipeline;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CategoryGenerationTransaction {
    public const META_KEYS = [
        '_tmwseo_quality_score','rank_math_title','rank_math_description','rank_math_focus_keyword',
        'rank_math_additional_keywords','_tmwseo_keyword','_tmwseo_secondary_keywords',
        '_tmwseo_rankmath_chip_report','_tmwseo_image_analysis','_tmwseo_ready_to_index',
        'rank_math_robots','_tmwseo_category_generation_status','_tmwseo_category_generation_error',
        '_tmwseo_category_last_save_result','_tmwseo_category_transaction_result',
        '_tmwseo_category_active_chip_set',
    ];
    public static function canonicalize(string $html): string { return str_replace(["\r\n","\r"], "\n", trim($html)); }
    private static function hash(string $html): string { return hash('sha256', self::canonicalize($html)); }
    private static function result(\WP_Post $post, string $fragment, string $intended, array $ctx): array {
        return [ 'ok'=>false,'written'=>false,'content_written'=>false,'verified'=>false,
            'run_id'=>(string)($ctx['run_id'] ?? ''),'post_id'=>(int)$post->ID,'target'=>'post_content',
            'source'=>(string)($ctx['source'] ?? $ctx['provider'] ?? 'category_generation'),
            'strategy'=>(string)($ctx['strategy'] ?? 'unknown'),'provider'=>(string)($ctx['provider'] ?? 'unknown'),
            'word_count'=>str_word_count(strip_tags($intended)),'failure_code'=>'','reasons'=>[],
            'old_content_hash'=>self::hash((string)$post->post_content),'fragment_hash'=>self::hash($fragment),
            'intended_content_hash'=>self::hash($intended),'persisted_content_hash'=>'','save_result'=>null,
            'rollback_status'=>'not_required','finalization_status'=>'not_started','post_commit_status'=>'not_started',
            'post_commit_reasons'=>[],'state'=>'not_started','readiness'=>null,
            'robots_before'=>get_post_meta((int)$post->ID,'rank_math_robots',true),'robots_after'=>null ];
    }
    /** @return array<string,mixed> */
    public static function commit(\WP_Post $post, string $fragment, string $final_document, array $ctx=[]): array {
        $id=(int)$post->ID; $r=self::result($post,$fragment,$final_document,$ctx); $run=$r['run_id'];
        if ($run==='') { $r['failure_code']='transaction_lock_failed'; $r['reasons']=['A category transaction requires a run ID.']; return $r; }
        $lock='tmwseo_category_transaction_lock_'.$id;
        if (!add_option($lock,$run,'','no')) { $r['failure_code']='transaction_lock_failed'; $r['reasons']=['Another category transaction is active.']; return $r; }
        try {
            $owner=(string)get_post_meta($id,'_tmwseo_category_generation_run_id',true);
            $ownership=self::non_owner_result($r,'transaction_superseded',['This request has been superseded by run '.$owner.'.'],['superseding_run_id'=>$owner]);
            if ($owner!=='' && $owner!==$run) return $ownership;
            $snapshot=self::snapshot($post); $r['state']='validated';
            if (trim($fragment)==='') return self::fail($r,$snapshot,'empty_generated_fragment',['Generated fragment is empty.']);
            if (isset($ctx['validate']) && is_callable($ctx['validate'])) { $v=call_user_func($ctx['validate'],$final_document); if (empty($v['ok'])) return self::fail($r,$snapshot,'blocked_persistence_guard',(array)($v['reasons']??['Final document validation failed.'])); }
            // Idempotent generation is a verified success without a needless write/revision.
            $stored=(string)get_post_field('post_content',$id);
            if (hash_equals(self::hash($final_document),self::hash($stored))) { $r['state']='readback_verified'; $r['verified']=true; $r['written']=true; $r['content_written']=true; }
            else {
                $save=wp_update_post(['ID'=>$id,'post_content'=>$final_document],true); $r['save_result']=is_wp_error($save)?['wp_error'=>$save->get_error_code(),'message'=>$save->get_error_message()]:$save;
                if (is_wp_error($save)||(int)$save<=0) return self::fail($r,$snapshot,'save_wp_error',[is_wp_error($save)?$save->get_error_message():'wp_update_post returned no post ID.']);
                $r['state']='content_mutated'; $r['written']=true; $r['content_written']=true; clean_post_cache($id); $stored=(string)get_post_field('post_content',$id);
                $r['persisted_content_hash']=self::hash($stored);
                if (!hash_equals($r['intended_content_hash'],$r['persisted_content_hash'])) return self::fail($r,$snapshot,'persisted_readback_mismatch',['Persisted content differs from the repaired intended document.']);
                $r['state']='readback_verified'; $r['verified']=true;
            }
            $r['persisted_content_hash']=self::hash($stored);
            foreach(['persist_metadata','persist_chips','evaluate_readiness','apply_robots'] as $step) if(isset($ctx[$step])&&is_callable($ctx[$step])) { call_user_func($ctx[$step],$id,$stored); $r['state']='metadata_mutated'; }
            $r['finalization_status']='complete'; $r['state']='finalized'; $r['ok']=true; $r['robots_after']=get_post_meta($id,'rank_math_robots',true);
            $owner=(string)get_post_meta($id,'_tmwseo_category_generation_run_id',true);
            if ($owner!=='' && $owner!==$run) return self::fail($r,$snapshot,'transaction_ownership_lost',['Transaction ownership changed before final result storage. Superseding run: '.$owner]);
            self::store($r);
            // Attachment work is explicitly post-commit: core category state never claims a rollback for it.
            if(isset($ctx['post_commit'])&&is_callable($ctx['post_commit'])) try { call_user_func($ctx['post_commit'],$id,$stored); $r['post_commit_status']='complete'; } catch(\Throwable $e) { $r['post_commit_status']='failed'; $r['post_commit_reasons']=[$e->getMessage()]; }
            self::store($r); return $r;
        } catch(\Throwable $e) { return self::fail($r,isset($snapshot)?$snapshot:null,'metadata_finalize_failed',[$e->getMessage()]); }
        finally { if ((string)get_option($lock,'')===$run) delete_option($lock); }
    }
    private static function snapshot(\WP_Post $post): array { $m=[]; foreach(self::META_KEYS as $k)$m[$k]=get_post_meta($post->ID,$k,false); return ['content'=>(string)$post->post_content,'meta'=>$m]; }
    private static function fail(array $r, ?array $snapshot, string $code, array $reasons): array {
        $r['failure_code']=$code; $r['reasons']=array_values(array_map('strval',$reasons));
        // Pre-write failures and WP errors have no rollback write. Only a proven mutation can be restored.
        if ($snapshot!==null && in_array($r['state'],['content_mutated','readback_verified','metadata_mutated','finalized'],true)) {
            $id=(int)$r['post_id']; $owner=(string)get_post_meta($id,'_tmwseo_category_generation_run_id',true);
            if($owner!=='' && $owner!==$r['run_id']) return self::non_owner_result($r,'transaction_ownership_lost',array_merge($r['reasons'],['Transaction ownership changed before rollback.']),['superseding_run_id'=>$owner,'rollback_status'=>'not_attempted']);
            wp_update_post(['ID'=>$id,'post_content'=>$snapshot['content']],true);
            foreach($snapshot['meta'] as $k=>$values){ delete_post_meta($id,$k); foreach($values as $v){ if(function_exists('add_post_meta')) add_post_meta($id,$k,$v); else update_post_meta($id,$k,$v); } }
            clean_post_cache($id); $bad=[];
            if(self::canonicalize((string)get_post_field('post_content',$id))!==self::canonicalize($snapshot['content']))$bad[]='post_content';
            foreach($snapshot['meta'] as $k=>$values) if(get_post_meta($id,$k,false)!==$values)$bad[]='meta:'.$k;
            $r['rollback_status']=$bad? 'verification_failed':'verified'; if($bad){$r['failure_code']='rollback_verification_failed';$r['reasons']=array_merge($r['reasons'],$bad);}
        }
        $r['finalization_status']='failed'; self::store($r); return $r;
    }
    private static function non_owner_result(array $r, string $code, array $reasons, array $extra=[]): array {
        $r['ok']=false; $r['verified']=false; $r['failure_code']=$code; $r['reasons']=array_values(array_map('strval',$reasons));
        $r['finalization_status']='failed';
        if(isset($extra['rollback_status'])) $r['rollback_status']=(string)$extra['rollback_status'];
        if(isset($extra['superseding_run_id'])) $r['superseding_run_id']=(string)$extra['superseding_run_id'];
        return $r;
    }
    private static function store(array $r): void { $id=(int)$r['post_id']; $json=wp_json_encode($r); update_post_meta($id,'_tmwseo_category_last_save_result',$json); update_post_meta($id,'_tmwseo_category_transaction_result',$json); }
}

