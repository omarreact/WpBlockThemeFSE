<?php
// Runs only inside disposable CI WordPress. Never run against a production database.
wp_set_current_user(1);
function source_assert($ok,$message){if(!$ok)throw new Exception($message);}
source_assert(wp_get_theme()->get_stylesheet()==='meta-grey-label-standalone','Wrong active theme');
source_assert(WP_Block_Type_Registry::get_instance()->is_registered('pin/source-layout'),'Source layout block not registered');
source_assert(count(pin_local_pages())===52,'Expected 52 bundled pages');
$legacy=pin_structured_config()['source_layout']['legacyPage'];
$existing=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Unrelated page','post_name'=>'home','post_content'=>'Preserve existing content'],true);
source_assert(!is_wp_error($existing),'Fixture setup failed');
$result=pin_local_import_batch(false);
source_assert(is_wp_error($result),'Unrelated conflict was not blocked');
source_assert(get_post($existing)->post_content==='Preserve existing content','Unrelated page overwritten');
update_post_meta($existing,$legacy['ownerKey'],$legacy['ownerValue']);update_post_meta($existing,$legacy['slugKey'],'home');
source_assert(is_wp_error(pin_local_import_batch(false)),'Legacy overwrite must be explicitly approved');
for($i=0;$i<20;$i++){
 $result=pin_local_import_batch(true);
 source_assert(!is_wp_error($result),is_wp_error($result)?$result->get_error_message():'Import error');
 if($result['done'])break;
}
source_assert(!empty($result['done']),'Import did not complete');
source_assert(count($result['ids'])===52,'Page count mismatch');
source_assert((int)get_option('page_on_front')===(int)$existing,'Legacy home not adopted');
source_assert(get_option('show_on_front')==='page','Homepage not assigned');
source_assert(get_option('permalink_structure')==='/%postname%/','Permalink structure mismatch');
$home=get_post($existing);
source_assert(has_block('pin/source-layout',$home),'Native source container missing');
$markup=do_blocks($home->post_content);
source_assert(str_contains($markup,'id="main-content"'),'Source main wrapper missing');
source_assert(str_contains($markup,'banner-section'),'Original hero class missing');
source_assert(str_contains($markup,'/source/assets/'),'Source asset URLs not resolved');
source_assert(!str_contains($markup,'/__pin_route__/'),'Unresolved route marker');
$header=get_block_template('meta-grey-label-standalone//header','wp_template_part');
source_assert($header&&str_contains($header->content,'mgl2-header'),'Source header missing');
$header_html=do_blocks($header->content);
source_assert(str_contains($header_html,'mgl-mega-column'),'Mega menu missing');
source_assert(!str_contains($header_html,'id="btnSwitch"'),'Unexpected theme toggle');
source_assert(count(pin_local_import_batch(true)['ids'])===52,'Repeat import failed');
echo "PASS: 52 native pages, protected conflicts, explicit legacy migration, idempotent import, source wrappers and local asset links.\n";
