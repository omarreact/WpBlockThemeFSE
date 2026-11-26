<?php
// Runs only inside disposable CI WordPress. Never run against a production database.
wp_set_current_user(1);
function source_assert($ok,$message){if(!$ok)throw new Exception($message);}
source_assert(wp_get_theme()->get_stylesheet()==='meta-grey-label-standalone','Wrong active theme');
source_assert(WP_Block_Type_Registry::get_instance()->is_registered('pin/source-layout'),'Source layout block not registered');
source_assert(count(pin_local_pages())===52,'Expected 52 bundled pages');
source_assert(WP_Block_Type_Registry::get_instance()->is_registered('pin/source-element'),'Source element block not registered');
$element=WP_Block_Type_Registry::get_instance()->get_registered('pin/source-element');
source_assert($element->api_version===3,'Expected block.json API version 3');
source_assert(!empty($element->supports['spacing']['padding']),'Block metadata spacing support missing');
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
$all = implode("\n",array_column(pin_local_pages(),'content'));
source_assert(str_contains($all,'wp:pin/source-element'),'No editable source elements generated');
source_assert(str_contains($header->content,'wp:pin/source-element'),'No editable header links or labels');
$fixture='<!-- wp:pin/source-layout {"tagName":"section","sourceAttrs":{"class":"pin-editor-fixture"}} --><!-- wp:pin/source-element {"tagName":"p","sourceAttrs":{"class":"fixture-text"},"content":"Original editor fixture"} /--><!-- /wp:pin/source-layout -->';
$id=wp_insert_post(wp_slash(['post_type'=>'page','post_status'=>'publish','post_name'=>'pin-editor-fixture','post_title'=>'PIN editor fixture','post_content'=>$fixture]),true);
source_assert(!is_wp_error($id)&&$id>0,'Editor fixture failed');
$parsed=parse_blocks(get_post($id)->post_content);
source_assert($parsed[0]['innerBlocks'][0]['attrs']['content']==='Original editor fixture','Block roundtrip failed');
source_assert(str_contains(do_blocks($fixture),'<p class="fixture-text">Original editor fixture</p>'),'Source text renderer changed markup');
$bad='<!-- wp:pin/source-element {"tagName":"script","sourceAttrs":{"onclick":"alert(1)"},"content":"<img onerror=alert(1)>"} /-->';
$rendered=do_blocks($bad);source_assert(!str_contains($rendered,'<script')&&!str_contains($rendered,'<img')&&!str_contains($rendered,'onclick='),'Unsafe source attributes were rendered');
echo "PASS: 52 native pages, protected conflicts, explicit legacy migration, idempotent import, source wrappers, editable elements and local asset links.\n";
