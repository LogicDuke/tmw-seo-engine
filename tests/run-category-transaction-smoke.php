<?php
require __DIR__ . '/bootstrap/wordpress-stubs.php';
require __DIR__ . '/../includes/content/category-pipeline/class-category-generation-transaction.php';
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationTransaction;
$GLOBALS['_tmw_test_posts']=[]; $GLOBALS['_tmw_test_post_meta']=[];
function tassert($ok,$name){static $n=0,$f=0;$n++;if(!$ok){$f++;echo "FAIL $name\n";}else echo "ok $name\n"; if($n===8){echo "PASS ".($n-$f)." FAIL $f\n";exit($f?1:0);}}
$p=new WP_Post(['ID'=>991,'post_content'=>'<p>old</p>','post_type'=>'tmw_category_page']);$GLOBALS['_tmw_test_posts'][991]=$p;
update_post_meta(991,'_tmwseo_category_generation_run_id','run-a'); update_post_meta(991,'rank_math_title','Old');
$r=CategoryGenerationTransaction::commit($p,'<p>new</p>','<p>new</p>',['run_id'=>'run-a','strategy'=>'template','provider'=>'template','validate'=>fn($x)=>['ok'=>true]]);
tassert($r['ok']&&$r['content_written']&&$r['verified'],'successful transaction preserves canonical save contract');
tassert(get_post_field('post_content',991)==='<p>new</p>'&&$r['intended_content_hash']===$r['persisted_content_hash'],'persisted content and hashes use intended document');
$r2=CategoryGenerationTransaction::commit(get_post(991),'<p>new</p>','<p>new</p>',['run_id'=>'run-a','validate'=>fn($x)=>['ok'=>true]]);
tassert($r2['ok']&&$r2['content_written']&&$r2['save_result']===null,'identical regeneration is verified without a write');
update_post_meta(991,'rank_math_title','Old');$before=get_post_field('post_content',991);
$r3=CategoryGenerationTransaction::commit(get_post(991),'','<p>x</p>',['run_id'=>'run-a']);
tassert($r3['failure_code']==='empty_generated_fragment'&&get_post_field('post_content',991)===$before,'empty fragment does not mutate or rollback');
$r4=CategoryGenerationTransaction::commit(get_post(991),'<p>x</p>','<p>x</p>',['run_id'=>'run-a','validate'=>fn($x)=>['ok'=>false,'reasons'=>['legacy sentence']]]);
tassert($r4['failure_code']==='blocked_persistence_guard'&&get_post_field('post_content',991)===$before,'validation rejection writes nothing');
update_post_meta(991,'_tmwseo_category_generation_run_id','new-run');$r5=CategoryGenerationTransaction::commit(get_post(991),'<p>x</p>','<p>x</p>',['run_id'=>'old-run']);
tassert($r5['failure_code']==='transaction_superseded','superseded run cannot write or rollback newer state');
tassert(isset($r['rollback_status'],$r['target'],$r['source'],$r['word_count']),'canonical result includes transaction fields');
tassert(get_post_meta(991,'_tmwseo_category_transaction_result',true)!=='','transaction result is persisted for AJAX');
