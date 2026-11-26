<?php
defined('ABSPATH') || exit;
/* Local source setup; no network, plugin installation or database reset. */
function pin_local_pages(){static $pages=null;if($pages===null)$pages=json_decode((string)file_get_contents(__DIR__.'/pin-local-pages.json'),true)?:[];return $pages;}
function pin_local_option(){return 'pin_local_setup_'.sanitize_key(pin_structured_config()['slug']);}
add_action('admin_enqueue_scripts',static function($hook){if($hook==='toplevel_page_pin-local-setup'){wp_enqueue_script('pin-local-setup',get_theme_file_uri('/local-setup.js'),[],'1.0.2',true);if(file_exists(__DIR__.'/setup.css'))wp_enqueue_style('pin-setup',get_theme_file_uri('/setup.css'),[],wp_get_theme()->get('Version'));}});
add_action('after_switch_theme',static function(){update_option(pin_local_option().'_redirect',true,false);});
add_action('admin_init',static function(){
 if(!current_user_can('manage_options')||wp_doing_ajax()||!get_option(pin_local_option().'_redirect'))return;
 delete_option(pin_local_option().'_redirect');
 wp_safe_redirect(admin_url('admin.php?page=pin-local-setup'));exit;
});
add_action('admin_menu',static function(){add_menu_page('Website setup','Website setup','manage_options','pin-local-setup','pin_local_screen','dashicons-admin-site-alt3',58);});
function pin_local_screen(){
 if(!current_user_can('manage_options'))return;
 if(function_exists('pin_release_setup_screen')){pin_release_setup_screen();return;}
 $state=get_option(pin_local_option(),[]);$pages=pin_local_pages();
 echo '<div class="wrap"><h1>'.esc_html(wp_get_theme()->get('Name')).' — website setup</h1><p>Import '.count($pages).' local WordPress pages. Existing unrelated content is never replaced. No external importer required.</p>';
 if(!empty($state['error']))echo '<div class="notice notice-error"><p>'.esc_html($state['error']).'</p></div>';
 echo '<p>Imported / matched: '.(int)($state['cursor']??0).' / '.count($pages).'</p>';
 if(!empty($state['done']))echo '<p><a class="button button-primary" href="'.esc_url(home_url('/')).'">View website</a> <a class="button" href="'.esc_url(admin_url('site-editor.php')).'">Edit site</a></p>';
 else{
 pin_local_form_open($state);
 wp_nonce_field('pin_local_import');
 if(!empty(pin_structured_config()['source_layout']['legacyPage']))echo '<p><label><input type="checkbox" name="replace_legacy" value="1" '.checked(pin_local_continue_mode($state)===1,true,false).'> Replace pages owned by the previous generated design (WordPress revisions retained where available).</label></p>';
 echo '<button class="button button-primary">Import &amp; Launch Website</button></form><p id="pin-local-progress" aria-live="polite">The importer uses normal page submissions. Progress is saved after each batch.</p>';
 }
 echo '</div>';
}
function pin_local_import_batch($replace_legacy=false){
 $pages=pin_local_pages();$key=pin_local_option();$state=get_option($key,[]);
 if(!empty($state['done']))return $state;
 if(!$pages)return new WP_Error('pin_empty','No bundled pages.');
 $cursor=(int)($state['cursor']??0);$ids=(array)($state['ids']??[]);
 $slug=pin_structured_config()['slug'];
 for($i=$cursor;$i<min(count($pages),$cursor+4);$i++){
  $p=$pages[$i];$route=$p['route'];$parent=$p['parent']?(int)($ids[$p['parent']]??0):0;
  if($p['parent']&&!$parent)return new WP_Error('pin_parent','Parent must be imported first: '.$route);
  $path=$route==='/'?'home':trim($route,'/');
  $page=get_page_by_path($path,OBJECT,'page');
  if($page){
   $owned=get_post_meta($page->ID,'_pin_structured_theme',true)===$slug&&get_post_meta($page->ID,'_pin_structured_route',true)===$route;
   $contract=pin_structured_config()['source_layout']['legacyPage']??[];
   $legacy=isset($contract['ownerKey'],$contract['ownerValue'],$contract['slugKey'])&&get_post_meta($page->ID,$contract['ownerKey'],true)===$contract['ownerValue']&&get_post_meta($page->ID,$contract['slugKey'],true)===($route==='/'?'home':$p['slug']);
   $previous=in_array(get_post_meta($page->ID,'_pin_structured_theme',true),pin_structured_config()['release']['previousThemeSlugs']??[],true)&&get_post_meta($page->ID,'_pin_structured_route',true)===$route;
   if(!$owned&&!($replace_legacy&&($legacy||$previous)))return new WP_Error('pin_conflict','Existing page conflict: '.$path.'. Existing content preserved.');
   $id=(int)$page->ID;
   if(!$owned&&$legacy&&!$previous){
    wp_save_post_revision($id);
    $updated=wp_update_post(wp_slash(['ID'=>$id,'post_content'=>$p['content'],'page_template'=>'default']),true);
    if(is_wp_error($updated))return $updated;
   }
  }else{
   $id=wp_insert_post(wp_slash(['post_type'=>'page','post_status'=>'publish','post_title'=>$p['title'],'post_name'=>$p['slug'],'post_parent'=>$parent,'post_content'=>$p['content'],'page_template'=>'default']),true);
   if(is_wp_error($id))return $id;
   if(!$id)return new WP_Error('pin_insert','Page creation failed: '.$path);
  }
  update_post_meta($id,'_pin_structured_theme',$slug);update_post_meta($id,'_pin_structured_route',$route);
  foreach(['title','description'] as $field){if(!empty($p['seo'][$field])&&!get_post_meta($id,'_pin_import_seo_'.$field,true))update_post_meta($id,'_pin_import_seo_'.$field,sanitize_text_field($p['seo'][$field]));}
  $ids[$route]=(int)$id;$state=['cursor'=>$i+1,'ids'=>$ids,'done'=>false];update_option($key,$state,false);
 }
 if($state['cursor']===count($pages)){
  if(empty($ids['/']))return new WP_Error('pin_home','Missing home page.');
  if(function_exists('pin_release_before_launch'))pin_release_before_launch();
  update_option('show_on_front','page');update_option('page_on_front',$ids['/']);
  global $wp_rewrite;$wp_rewrite->set_permalink_structure('/%postname%/');flush_rewrite_rules(false);
  if(in_array(get_option('blogname'),['My Blog',''],true))update_option('blogname',wp_get_theme()->get('Name'));
  if(function_exists('pin_release_site_icon'))pin_release_site_icon();
  $state['done']=true;update_option($key,$state,false);
 }
 return $state;
}
/** Continuation is signed for the current user, migration choice and saved cursor. */
function pin_local_continue_mode($state){
 if(!empty($state['error'])||!empty($state['done']))return -1;
 $token=$_GET['pin_continue']??'';$mode=$_GET['pin_migrate']??'0';
 if(!is_string($token)||!in_array($mode,['0','1'],true))return -1;
 return wp_verify_nonce($token,'pin_local_continue_'.(int)($state['cursor']??0).'_'.$mode)?(int)$mode:-1;
}
function pin_local_form_open($state){
 $mode=pin_local_continue_mode($state);
 echo '<form id="pin-local-import" method="post" action="'.esc_url(admin_url('admin.php?page=pin-local-setup')).'" data-pin-continue="'.($mode>=0?'1':'0').'"><input type="hidden" name="pin_setup_submit" value="1">';
}
add_action('admin_init',static function(){
 global $pagenow;
 if($pagenow==='admin.php'&&($_GET['page']??'')==='pin-local-setup'&&($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['pin_setup_submit']??'')==='1')pin_local_import_request();
},5);
add_action('admin_post_pin_local_import','pin_local_import_request');
function pin_local_import_request(){
 if(!current_user_can('manage_options'))wp_die('Forbidden','',['response'=>403]);
 check_admin_referer('pin_local_import');
 $json=isset($_POST['pin_json'])&&$_POST['pin_json']==='1';
 $replace=($_POST['replace_legacy']??'')==='1';
 $lock=pin_local_option().'_lock';
 $held=get_option($lock);
 if($held&&time()-(int)$held>300)delete_option($lock);
 if(!add_option($lock,time(),'','no')){
   if($json)wp_send_json_error(['message'=>'Another import request is running.'],409);
   wp_die('Another import request is running.','',['response'=>409]);
 }
 try{$state=pin_local_import_batch($replace);
 if(is_wp_error($state)){$old=get_option(pin_local_option(),[]);$old['error']=$state->get_error_message();update_option(pin_local_option(),$old,false);}
 }finally{delete_option($lock);}
 if($json){
   if(is_wp_error($state))wp_send_json_error(['message'=>$state->get_error_message()],409);
   wp_send_json_success(['cursor'=>(int)$state['cursor'],'total'=>count(pin_local_pages()),'done'=>!empty($state['done'])]);
 }
 $url=admin_url('admin.php?page=pin-local-setup');
 if(!is_wp_error($state)&&empty($state['done'])){
   $mode=$replace?'1':'0';
   $url=add_query_arg(['pin_continue'=>wp_create_nonce('pin_local_continue_'.(int)$state['cursor'].'_'.$mode),'pin_migrate'=>$mode],$url);
 }
 wp_safe_redirect($url,303);exit;
}
