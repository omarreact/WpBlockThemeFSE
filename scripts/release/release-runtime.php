<?php
/** Opt-in release features; all authoring remains in WordPress. */
defined('ABSPATH') || exit;

function pin_release_profile(){return pin_structured_config()['release']??[];}
add_action('wp_enqueue_scripts',static function(){wp_enqueue_style('pin-release',get_theme_file_uri('/release.css'),[],wp_get_theme()->get('Version'));},110);
add_action('enqueue_block_assets',static function(){if(is_admin())wp_enqueue_style('pin-release-editor',get_theme_file_uri('/release.css'),[],wp_get_theme()->get('Version'));},110);
add_filter('query_loop_block_query_vars',static function($query,$block){
 if(($block->parsed_block['attrs']['namespace']??'')==='pin/related'&&is_singular('post')){
  $id=get_queried_object_id();$query['post__not_in']=[$id];$categories=wp_get_post_categories($id);
  if($categories)$query['category__in']=$categories;
 }
 return $query;
},10,2);

function pin_release_before_launch(){
 if(!get_option(pin_local_option().'_before'))add_option(pin_local_option().'_before',['show_on_front'=>get_option('show_on_front'),'page_on_front'=>get_option('page_on_front'),'page_for_posts'=>get_option('page_for_posts'),'permalink_structure'=>get_option('permalink_structure'),'blogname'=>get_option('blogname')],'','no');
}
function pin_release_site_icon(){
 if(get_option('site_icon'))return;
 $rel=pin_release_profile()['siteIcon']??'';
 if(!$rel||!preg_match('~^[a-zA-Z0-9_./-]+\.png$~D',$rel)||str_contains($rel,'..'))return;
 $file=get_theme_file_path('/source/'.$rel);
 if(!is_readable($file))return;
 $upload=wp_upload_bits('site-icon-512.png',null,file_get_contents($file));
 if(!empty($upload['error']))return;
 $id=wp_insert_attachment(['post_mime_type'=>'image/png','post_title'=>'Website icon','post_status'=>'inherit'],$upload['file'],0,true);
 if(is_wp_error($id)){wp_delete_file($upload['file']);return;}
 require_once ABSPATH.'wp-admin/includes/image.php';
 wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$upload['file']));
 update_option('site_icon',$id);
}
function pin_release_setup_screen(){
 $state=get_option(pin_local_option(),[]);$total=count(pin_local_pages());$count=(int)($state['cursor']??0);$done=!empty($state['done']);
 $name=wp_get_theme()->get('Name');$version=wp_get_theme()->get('Version');
 echo '<div class="wrap pin-setup"><div class="pin-setup-hero"><div><span class="pin-setup-version">VERSION '.esc_html($version).' · FULL SITE EDITING</span><h1>'.esc_html($name).' — Website setup</h1><p>Install your local website content, then customize every supported block in the WordPress Site Editor. No remote importer or subscription required.</p></div></div>';
 if(!empty($state['error']))echo '<div class="notice notice-error"><p>'.esc_html($state['error']).'</p></div>';
 echo '<div class="pin-setup-grid"><section class="pin-setup-card"><h2>Website preview</h2>';
 if(file_exists(get_theme_file_path('/screenshot.png')))echo '<img src="'.esc_url(get_theme_file_uri('/screenshot.png')).'" alt="Homepage preview">';
 echo '<p>Native page layouts, shared header and footer, blog templates and local assets.</p><div class="pin-setup-note">Before upgrading, back up the database, media and previous theme. Prior Site Editor template customizations remain with the previous theme.</div></section><section class="pin-setup-card"><h2>'.($done?'Website ready':'Import & launch').'</h2><div class="pin-setup-count"><strong>Pages matched or imported</strong><span id="pin-import-count">'.$count.' / '.$total.'</span></div><progress id="pin-import-bar" max="'.max(1,$total).'" value="'.$count.'"></progress>';
 if($done)echo '<p><a class="button button-primary" href="'.esc_url(home_url('/')).'">View website</a> <a class="button" href="'.esc_url(admin_url('site-editor.php')).'">Edit site</a></p>';
 else{
 pin_local_form_open($state);wp_nonce_field('pin_local_import');
 echo '<label><input type="checkbox" name="replace_legacy" value="1" '.checked(pin_local_continue_mode($state)===1,true,false).'> Adopt compatible previously imported pages, or replace explicitly marked older source pages. Unrelated pages are protected.</label><button class="button button-primary">Import &amp; Launch Website</button></form><p id="pin-local-progress" aria-live="polite">Progress is saved after each batch. If asked to log in, return here and resume.</p>';
 }
 echo '<hr><h2>Contact enquiries</h2><p>Delivery is disabled until you configure a valid recipient. Verify that your hosting mail transport works.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="pin_release_contact_settings">';wp_nonce_field('pin_release_contact_settings');
 echo '<label for="pin-recipient">Recipient email</label><input id="pin-recipient" type="email" name="recipient" value="'.esc_attr(get_option('pin_release_recipient','')).'"><label><input type="checkbox" name="enabled" value="1" '.checked((bool)get_option('pin_release_contact_enabled'),true,false).'> Enable enquiry delivery</label><button class="button">Save contact settings</button></form></section></div><p class="pin-setup-footer">Edit templates and Global Styles in Appearance → Editor. Publish new articles in Posts → Add New.</p></div>';
}
add_action('admin_post_pin_release_contact_settings',static function(){
 if(!current_user_can('manage_options'))wp_die('Forbidden','',['response'=>403]);check_admin_referer('pin_release_contact_settings');
 $raw=wp_unslash($_POST['recipient']??'');$email=is_string($raw)?sanitize_email($raw):'';
 update_option('pin_release_recipient',is_email($email)?$email:'',false);
 update_option('pin_release_contact_enabled',!empty($_POST['enabled'])&&is_email($email),false);
 wp_safe_redirect(admin_url('admin.php?page=pin-local-setup'));exit;
});
add_shortcode('pin_release_contact',static function(){
 $enabled=get_option('pin_release_contact_enabled')&&is_email(get_option('pin_release_recipient',''));
 if(!$enabled)return '';
 $raw=wp_unslash($_GET['enquiry']??'');$status=is_string($raw)?sanitize_key($raw):'';
 ob_start();?>
 <h2>Send an enquiry</h2>
 <?php if($status==='sent'):?><p role="status">Your enquiry was accepted by the website mail system.</p><?php elseif($status==='failed'):?><p role="alert">Delivery could not be confirmed. Please try again later.</p><?php endif;?>
 <form action="<?php echo esc_url(admin_url('admin-post.php'));?>" method="post">
 <input type="hidden" name="action" value="pin_release_enquiry"><?php wp_nonce_field('pin_release_enquiry');?>
 <label for="pin-name">Name</label><input id="pin-name" name="name" required maxlength="120" autocomplete="name">
 <label for="pin-email">Email</label><input id="pin-email" name="email" type="email" required maxlength="254" autocomplete="email">
 <label for="pin-message">Message</label><textarea id="pin-message" name="message" required maxlength="4000" rows="6"></textarea>
 <div class="pin-honeypot" aria-hidden="true"><label>Leave blank<input name="website" tabindex="-1" autocomplete="off"></label></div>
 <button type="submit">Send enquiry</button></form>
 <?php return ob_get_clean();
});
function pin_release_enquiry_send($input){
 if(!get_option('pin_release_contact_enabled'))return false;
 $to=get_option('pin_release_recipient','');if(!is_email($to)||!empty($input['website']))return false;
 foreach(['name','email','message'] as $field)if(!isset($input[$field])||!is_string($input[$field]))return false;
 $name=sanitize_text_field($input['name']);$email=sanitize_email($input['email']);$message=sanitize_textarea_field($input['message']);
 if(!$name||!is_email($email)||!$message||strlen($name)>120||strlen($message)>4000)return false;
 return wp_mail($to,'Website enquiry — '.wp_get_theme()->get('Name'),"Name: $name\nEmail: $email\n\n$message",['Reply-To: '.$email]);
}
function pin_release_enquiry_handler(){
 check_admin_referer('pin_release_enquiry');
 $ip=wp_unslash($_SERVER['REMOTE_ADDR']??'unknown');$key='pin_enq_'.substr(hash_hmac('sha256',$ip,wp_salt('nonce')),0,32);
 $ok=false;if(!get_transient($key)){set_transient($key,1,60);$ok=pin_release_enquiry_send(wp_unslash($_POST));}
 $route=pin_release_profile()['contactPage']??'contact';
 wp_safe_redirect(add_query_arg('enquiry',$ok?'sent':'failed',home_url('/'.$route.'/')));exit;
}
add_action('admin_post_pin_release_enquiry','pin_release_enquiry_handler');
add_action('admin_post_nopriv_pin_release_enquiry','pin_release_enquiry_handler');

function pin_release_seo_plugin(){return defined('WPSEO_VERSION')||defined('RANK_MATH_VERSION')||defined('AIOSEO_VERSION')||defined('SEOPRESS_VERSION');}
add_filter('pre_get_document_title',static function($title){
 if(pin_release_seo_plugin()||!is_singular())return $title;
 $value=get_post_meta(get_queried_object_id(),'_pin_import_seo_title',true);
 return $value?:$title;
});
add_action('wp_head',static function(){
 if(pin_release_seo_plugin()||(!is_singular()&&!is_front_page()))return;
 $id=get_queried_object_id();$post=get_post($id);if(!$post)return;
 $title=get_post_meta($id,'_pin_import_seo_title',true)?:get_the_title($id);
 $desc=get_post_meta($id,'_pin_import_seo_description',true)?:wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_excerpt?:$post->post_content)),28);
 $url=get_permalink($id);$image=get_the_post_thumbnail_url($id,'large');
 if($desc)echo '<meta name="description" content="'.esc_attr($desc).'">'."\n";
 foreach(['og:title'=>$title,'og:description'=>$desc,'og:url'=>$url,'og:type'=>is_singular('post')?'article':'website'] as $k=>$v)if($v)echo '<meta property="'.esc_attr($k).'" content="'.esc_attr($v).'">'."\n";
 if($image)echo '<meta property="og:image" content="'.esc_url($image).'">'."\n";
 if(is_singular('post'))echo '<script type="application/ld+json">'.wp_json_encode(['@context'=>'https://schema.org','@type'=>'BlogPosting','headline'=>$title,'datePublished'=>get_the_date(DATE_W3C,$id),'dateModified'=>get_the_modified_date(DATE_W3C,$id),'mainEntityOfPage'=>$url],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>'."\n";
},5);
