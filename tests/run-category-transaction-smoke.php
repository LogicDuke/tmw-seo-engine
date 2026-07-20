<?php
require __DIR__ . '/bootstrap/wordpress-stubs.php';
require __DIR__ . '/../includes/content/category-pipeline/class-category-generation-transaction.php';
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationTransaction;
$GLOBALS['_tmw_test_posts']=[]; $GLOBALS['_tmw_test_post_meta']=[]; $GLOBALS['_tmw_test_options']=[];
$tests=0;$fails=0;function tassert($ok,$name){global $tests,$fails;$tests++;if(!$ok){$fails++;echo "FAIL $name\n";}else echo "ok $name\n";}
function finish(){global $tests,$fails;echo "PASS ".($tests-$fails)." FAIL $fails\n";exit($fails?1:0);} 
function seed_post($id,$content,$run){$GLOBALS['_tmw_test_posts'][$id]=new WP_Post(['ID'=>$id,'post_content'=>$content,'post_type'=>'tmw_category_page']);update_post_meta($id,'_tmwseo_category_generation_run_id',$run);update_post_meta($id,'rank_math_title','Old Title');update_post_meta($id,'_tmwseo_ready_to_index','0');update_post_meta($id,'rank_math_robots',['noindex']);update_post_meta($id,'_tmwseo_rankmath_chip_report','old chips');}
seed_post(991,'<p>old</p>','run-a');
$r=CategoryGenerationTransaction::commit(get_post(991),'<p>new</p>','<p>new</p>',['run_id'=>'run-a','strategy'=>'template','provider'=>'template','validate'=>fn($x)=>['ok'=>true]]);
tassert($r['ok']&&$r['content_written']&&$r['verified'],'successful transaction preserves canonical save contract');
tassert(get_post_field('post_content',991)==='<p>new</p>'&&$r['intended_content_hash']===$r['persisted_content_hash'],'persisted content and hashes use intended document');
$storedSuccess=get_post_meta(991,'_tmwseo_category_transaction_result',true);
tassert($storedSuccess!==''&&strpos($storedSuccess,'"run_id":"run-a"')!==false,'current owner stores success result');
$r2=CategoryGenerationTransaction::commit(get_post(991),'<p>new</p>','<p>new</p>',['run_id'=>'run-a','validate'=>fn($x)=>['ok'=>true]]);
tassert($r2['ok']&&$r2['content_written']&&$r2['save_result']===null,'identical regeneration is verified without a write');
$before=get_post_field('post_content',991);$beforeMeta=get_post_meta(991,'_tmwseo_category_transaction_result',true);
$r3=CategoryGenerationTransaction::commit(get_post(991),'','<p>x</p>',['run_id'=>'run-a']);
tassert($r3['failure_code']==='empty_generated_fragment'&&get_post_field('post_content',991)===$before,'empty fragment does not mutate or rollback');
$r4=CategoryGenerationTransaction::commit(get_post(991),'<p>x</p>','<p>x</p>',['run_id'=>'run-a','validate'=>fn($x)=>['ok'=>false,'reasons'=>['legacy sentence']]]);
tassert($r4['failure_code']==='blocked_persistence_guard'&&get_post_field('post_content',991)===$before,'validation rejection writes nothing');
update_post_meta(991,'_tmwseo_category_generation_run_id','run-b');
$rb=CategoryGenerationTransaction::commit(get_post(991),'<p>b</p>','<p>b</p>',['run_id'=>'run-b','strategy'=>'provider','provider'=>'openai','validate'=>fn($x)=>['ok'=>true],'persist_metadata'=>fn($id,$html)=>update_post_meta($id,'rank_math_title','Run B Title')]);
$runBResult=get_post_meta(991,'_tmwseo_category_transaction_result',true);$runBMeta=get_post_meta(991,'rank_math_title',true);$runBContent=get_post_field('post_content',991);$runBRobots=get_post_meta(991,'rank_math_robots',true);$runBChips=get_post_meta(991,'_tmwseo_rankmath_chip_report',true);
$ra=CategoryGenerationTransaction::commit(get_post(991),'<p>a stale</p>','<p>a stale</p>',['run_id'=>'run-a','validate'=>fn($x)=>['ok'=>true]]);
tassert($rb['ok']&&$ra['failure_code']==='transaction_superseded'&&($ra['superseding_run_id']??'')==='run-b','stale run returns superseded with superseding run ID');
tassert(get_post_meta(991,'_tmwseo_category_transaction_result',true)===$runBResult&&get_post_meta(991,'_tmwseo_category_last_save_result',true)===$runBResult,'superseded run does not overwrite stored run B result');
tassert(get_post_field('post_content',991)===$runBContent&&get_post_meta(991,'rank_math_title',true)===$runBMeta&&get_post_meta(991,'rank_math_robots',true)===$runBRobots&&get_post_meta(991,'_tmwseo_rankmath_chip_report',true)===$runBChips,'superseded run does not alter content, metadata, robots, or chips');
$lock='tmwseo_category_transaction_lock_991';update_option($lock,'run-b');$beforeLockResult=get_post_meta(991,'_tmwseo_category_transaction_result',true);$rl=CategoryGenerationTransaction::commit(get_post(991),'<p>lock</p>','<p>lock</p>',['run_id'=>'run-c']);delete_option($lock);
tassert($rl['failure_code']==='transaction_lock_failed'&&get_post_meta(991,'_tmwseo_category_transaction_result',true)===$beforeLockResult,'lock failure returns directly without shared result mutation');
update_post_meta(991,'_tmwseo_category_generation_run_id','run-d');$beforeOwnershipResult=get_post_meta(991,'_tmwseo_category_transaction_result',true);$ro=CategoryGenerationTransaction::commit(get_post(991),'<p>d</p>','<p>d</p>',['run_id'=>'run-d','validate'=>fn($x)=>['ok'=>true],'persist_metadata'=>function($id,$html){update_post_meta($id,'_tmwseo_category_generation_run_id','run-e');}]);
tassert($ro['failure_code']==='transaction_ownership_lost'&&get_post_meta(991,'_tmwseo_category_transaction_result',true)===$beforeOwnershipResult,'ownership lost before final storage does not overwrite shared result');
update_post_meta(991,'_tmwseo_category_generation_run_id','run-f');$beforeRollbackResult=get_post_meta(991,'_tmwseo_category_transaction_result',true);$rr=CategoryGenerationTransaction::commit(get_post(991),'<p>f</p>','<p>f</p>',['run_id'=>'run-f','validate'=>fn($x)=>['ok'=>true],'persist_metadata'=>function($id,$html){update_post_meta($id,'_tmwseo_category_generation_run_id','run-g');throw new RuntimeException('lost after metadata');}]);
tassert($rr['failure_code']==='transaction_ownership_lost'&&$rr['rollback_status']==='not_attempted'&&get_post_meta(991,'_tmwseo_category_transaction_result',true)===$beforeRollbackResult,'ownership lost before rollback does not write rollback or shared result');
update_post_meta(991,'_tmwseo_category_generation_run_id','run-h');update_post_meta(991,'rank_math_focus_keyword','old focus');update_post_meta(991,'rank_math_title','Old Title');$oldContent=get_post_field('post_content',991);$rf=CategoryGenerationTransaction::commit(get_post(991),'<p>h</p>','<p>h</p>',['run_id'=>'run-h','validate'=>fn($x)=>['ok'=>true],'persist_metadata'=>function($id,$html){update_post_meta($id,'rank_math_focus_keyword','partial focus');update_post_meta($id,'rank_math_title','Partial Title');throw new RuntimeException('metadata failure');}]);
tassert($rf['failure_code']==='metadata_finalize_failed'&&$rf['rollback_status']==='verified','failed generation rolls back content and metadata with verified readback');
tassert(get_post_field('post_content',991)===$oldContent&&get_post_meta(991,'rank_math_focus_keyword',true)==='old focus'&&get_post_meta(991,'rank_math_title',true)==='Old Title','failed generation leaves no partial Rank Math focus-keyword or active-contract metadata');
tassert(isset($r['rollback_status'],$r['target'],$r['source'],$r['word_count']),'canonical result includes transaction fields');
finish();
