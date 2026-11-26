<?php
// Runs only inside disposable CI WordPress. Never run against a production database.
wp_set_current_user(1);
function source_assert($ok,$message){if(!$ok)throw new Exception($message);}
source_assert(wp_get_theme()->get_stylesheet()==='meta-grey-label','Wrong active theme');
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
$header=get_block_template('meta-grey-label//header','wp_template_part');
source_assert($header&&str_contains($header->content,'mgl2-header'),'Source header missing');
$header_html=do_blocks($header->content);
source_assert(str_contains($header_html,'mgl-mega-column'),'Mega menu missing');
source_assert(!str_contains($header_html,'id="btnSwitch"'),'Unexpected theme toggle');
source_assert(count(pin_local_import_batch(true)['ids'])===52,'Repeat import failed');
$all = implode("\n",array_column(pin_local_pages(),'content'));
source_assert(str_contains($all,'wp:pin/source-element'),'No editable source elements generated');
source_assert(str_contains($header->content,'wp:pin/source-element'),'No editable header links or labels');
$tree=parse_blocks($home->post_content);
$layout_classes=[];
function collect_source_classes($blocks,&$classes){foreach($blocks as $b){if($b['blockName']==='pin/source-layout')$classes[]=$b['attrs']['sourceAttrs']['class']??'';if(!empty($b['innerBlocks']))collect_source_classes($b['innerBlocks'],$classes);}}
collect_source_classes($tree,$layout_classes);
source_assert(in_array('swiper-wrapper',$layout_classes,true),'Slider children not editable');
source_assert(in_array('accordion-box',$layout_classes,true),'Accordion children not editable');
$fixture='<!-- wp:pin/source-layout {"tagName":"section","sourceAttrs":{"class":"pin-editor-fixture"}} --><!-- wp:pin/source-element {"tagName":"p","sourceAttrs":{"class":"fixture-text"},"content":"Original editor fixture"} /--><!-- /wp:pin/source-layout -->';
$id=wp_insert_post(wp_slash(['post_type'=>'page','post_status'=>'publish','post_name'=>'pin-editor-fixture','post_title'=>'PIN editor fixture','post_content'=>$fixture]),true);
source_assert(!is_wp_error($id)&&$id>0,'Editor fixture failed');
$parsed=parse_blocks(get_post($id)->post_content);
source_assert($parsed[0]['innerBlocks'][0]['attrs']['content']==='Original editor fixture','Block roundtrip failed');
source_assert(str_contains(do_blocks($fixture),'<p class="fixture-text">Original editor fixture</p>'),'Source text renderer changed markup');
$bad='<!-- wp:pin/source-element {"tagName":"script","sourceAttrs":{"onclick":"alert(1)"},"content":"<img onerror=alert(1)>"} /-->';
$rendered=do_blocks($bad);source_assert(!str_contains($rendered,'<script')&&!str_contains($rendered,'<img')&&!str_contains($rendered,'onclick='),'Unsafe source attributes were rendered');
source_assert(wp_get_theme()->get('Version')==='0.0.1','Release version mismatch');
$asset=trailingslashit(get_theme_file_uri('/source')).'assets/example.png';
source_assert(pin_structured_urls($asset)===$asset,'Already resolved URL was rewritten twice');
source_assert(pin_structured_urls('/wp-content/themes/meta-grey-label-standalone/source/assets/example.png')===$asset,'Legacy relative asset not migrated');
source_assert(pin_structured_urls(content_url('/themes/meta-grey-label-standalone/source/assets/example.png'))===$asset,'Legacy absolute asset not migrated');
source_assert((bool)get_post_meta($existing,'_pin_import_seo_title',true),'SEO metadata not imported');
source_assert((bool)get_option('site_icon'),'Site icon not imported');
source_assert((bool)get_option(pin_local_option().'_before'),'Settings snapshot missing');
foreach(['single','home','archive','category','tag','author','search','404','page-insights','page-contact'] as $template){
 source_assert((bool)get_block_template('meta-grey-label//'.$template,'wp_template'),'Missing template '.$template);
}
$post=wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_name'=>'pin-release-blog','post_title'=>'PIN release blog fixture','post_content'=>'<!-- wp:paragraph --><p>Native blog fixture body.</p><!-- /wp:paragraph -->'],true);
source_assert(!is_wp_error($post),'Post fixture failed');
update_option('pin_release_recipient','qa@example.invalid');update_option('pin_release_contact_enabled',true);
add_filter('pre_wp_mail',static function($pre,$atts){source_assert($atts['to']==='qa@example.invalid','Wrong recipient');return true;},10,2);
source_assert(pin_release_enquiry_send(['name'=>'QA','email'=>'qa@example.invalid','message'=>'Fixture only']),'Mail handler fixture failed');
source_assert(!pin_release_enquiry_send(['name'=>'QA','email'=>'invalid','message'=>'Fixture only']),'Invalid email accepted');
source_assert(!pin_release_enquiry_send(['name'=>'QA','email'=>['invalid'],'message'=>'Fixture only']),'Array email accepted');
source_assert(!pin_release_enquiry_send(['name'=>'QA','email'=>'qa@example.invalid','message'=>'Fixture','website'=>'spam']),'Honeypot bypass');
echo "PASS: release identity, 52 native pages, protected conflicts, explicit legacy migration, native blog templates, SEO metadata, icon, mocked mail and editable elements.\n";
