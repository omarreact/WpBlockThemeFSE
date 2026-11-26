<?php
/** Source-specific release behavior; never modifies imported page records. */
defined('ABSPATH') || exit;
function pin_forexy_route($route) {
    return !is_admin() && is_singular('page') && get_post_meta(get_queried_object_id(), '_pin_structured_route', true) === $route;
}
function pin_forexy_has_brief() {
    $post=get_post(get_queried_object_id());
    return $post && strpos($post->post_content, 'id="mgl-enquiry-form"') !== false;
}
function pin_forexy_mail_enabled() {
    return (bool)get_option('pin_release_contact_enabled') && (bool)is_email(get_option('pin_release_recipient',''));
}
function pin_forexy_form($markup) {
    if(strpos($markup,'id="mgl-enquiry-form"')===false)return $markup;
    $enabled=pin_forexy_mail_enabled();
    $p=new WP_HTML_Tag_Processor($markup);
    if(!$p->next_tag(['tag_name'=>'FORM','class_name'=>'mgl-contact-form']))return $markup;
    $p->set_attribute('method','post');$p->set_attribute('action',admin_url('admin-post.php'));$p->set_attribute('data-mgl-send-enabled',$enabled?'1':'0');
    $markup=$p->get_updated_html();
    $hidden='<input type="hidden" name="action" value="pin_release_enquiry"><input type="hidden" name="mgl_structured" value="1">'.wp_nonce_field('pin_release_enquiry','_wpnonce',true,false).'<div class="pin-honeypot" aria-hidden="true"><label>Leave blank<input name="website" tabindex="-1" autocomplete="off"></label></div>';
    $markup=preg_replace_callback('~<form\b[^>]*\bid="mgl-enquiry-form"[^>]*>~i',static function($m)use($hidden){return $m[0].$hidden;},$markup,1);
    if($enabled){
        $markup=str_replace('Email or contact details','Email address (required to send)',$markup);
        $markup=str_replace('No data is sent automatically from this page.','Prepare a copy or send your project brief to our team.',$markup);
        $markup=str_replace('</form>','<div class="mgl-contact-send"><button class="mgl-home-btn mgl-home-btn--primary" type="submit" name="pin_send" value="1">Send enquiry</button><p>Sending requires a valid email address. Preparing a brief does not send any data.</p></div></form>',$markup);
    }
    $raw=wp_unslash($_GET['enquiry']??'');$status=is_string($raw)?sanitize_key($raw):'';
    if($status==='sent')$markup='<p class="mgl-delivery-notice" role="status">Your enquiry was accepted by the website mail system.</p>'.$markup;
    elseif($status==='failed')$markup='<p class="mgl-delivery-notice" role="alert">Enquiry not sent. Check the required fields and email address, or try again later. You can still prepare and copy a brief.</p>'.$markup;
    return $markup;
}
add_filter('render_block_core/html',static function($markup){
    if(!pin_forexy_route('/contact/'))return $markup;
    if(strpos($markup,'id="mgl-share-enquiry"')!==false)$markup=preg_replace('~<a\b[^>]*id="mgl-share-enquiry"[^>]*>.*?</a>~is','',$markup);
    return pin_forexy_form($markup);
},20);
add_filter('render_block_pin/source-element',static function($markup,$block){
    if(pin_forexy_route('/contact/')&&($block['attrs']['content']??'')==='Review it, then copy it or send it directly to our Contact contact.')return str_replace('Review it, then copy it or send it directly to our Contact contact.','Review and copy your brief. If delivery is enabled, use Send enquiry in the project form.',$markup);
    return $markup;
},20,2);
add_filter('pre_do_shortcode_tag',static function($return,$tag){
    return $tag==='pin_release_contact'&&pin_forexy_route('/contact/')&&pin_forexy_has_brief()?'':$return;
},10,2);
function pin_forexy_structured_input($input) {
    if(($input['pin_send']??'')!=='1')return false;
    foreach(['name','contact','model','platform'] as $key)if(!isset($input[$key])||!is_string($input[$key])||trim($input[$key])==='')return false;
    if(!is_email(trim($input['contact'])))return false;
    $fields=['company'=>'Company / brand','market'=>'Target market','model'=>'Launch model','platform'=>'Trading platform','execution'=>'Execution / liquidity','operations'=>'Client operations','timeline'=>'Launch window','status'=>'Current status','message'=>'Additional requirements'];
    $lines=[];
    foreach($fields as $key=>$label){
        $v=$input[$key]??'';if(!is_string($v)||strlen($v)>($key==='message'?3000:200))return false;
        $v=$key==='message'?sanitize_textarea_field($v):sanitize_text_field($v);if($v!=='')$lines[]=$label.': '.$v;
    }
    return ['name'=>$input['name'],'email'=>trim($input['contact']),'message'=>implode("\n",$lines),'website'=>$input['website']??''];
}
// Existing installations keep their saved pages, while the original unedited
// banner markup receives the same semantic corrections as newly exported pages.
function pin_forexy_home_markup($markup) {
    $count=0;
    $markup=preg_replace_callback('~<h([12])\b([^>]*\bclass="[^"]*\btitle\b[^"]*\banimate-2\b[^"]*"[^>]*)>(.*?)</h\1>~is',static function($m)use(&$count){
        $tag=(++$count===1)?'h1':'h2';return '<'.$tag.$m[2].'>'.$m[3].'</'.$tag.'>';
    },$markup);
    return preg_replace_callback('~<a\b[^>]*>.*?</a>~is',static function($m){
        $a=$m[0];$p=new WP_HTML_Tag_Processor($a);if(!$p->next_tag('a'))return $a;
        if(trim(wp_strip_all_tags($a))==='Discuss Your Setup' && in_array($p->get_attribute('href'),[home_url('/about-us/'),home_url('/about-us')],true))$p->set_attribute('href',home_url('/contact/'));
        if($p->has_class('play-now-two')&&!$p->get_attribute('aria-label'))$p->set_attribute('aria-label','Explore trading platforms');
        return $p->get_updated_html();
    },$markup);
}
add_filter('the_content',static function($markup){return is_front_page()&&pin_forexy_route('/')?pin_forexy_home_markup($markup):$markup;},20);
