<?php
defined('ABSPATH') || exit;
function pin_source_layout_render($attrs,$content) {
    $tag=$attrs['tagName']??'div';
    if(!in_array($tag,['div','main','section','article','aside','header','footer'],true))$tag='div';
    $raw=(array)($attrs['sourceAttrs']??[]);
    $native=false;
    foreach(['style','align','backgroundColor','textColor','gradient','fontSize','fontFamily','className'] as $key)if(!empty($attrs[$key])){$native=true;break;}
    $out='<'.$tag;
    if($native){
        $extra=[];
        if(isset($raw['class']))$extra['class']=(string)$raw['class'];
        if(isset($raw['style']))$extra['style']=safecss_filter_attr((string)$raw['style']);
        $out.=' '.get_block_wrapper_attributes($extra);
        unset($raw['class'],$raw['style']);
    }
    foreach($raw as $key=>$value){
        if(!is_string($value)||!preg_match('/^(?:class|id|role|title|lang|dir|tabindex|hidden|style|aria-[a-z0-9_-]+|data-[a-z0-9_-]+)$/D',$key))continue;
        if($key==='style')$value=safecss_filter_attr($value);
        $out.=' '.$key.'="'.esc_attr($value).'"';
    }
    return $out.'>'.$content.'</'.$tag.'>';
}
add_action('init',static function(){
    wp_register_script('pin-source-layout-editor',get_theme_file_uri('/source-layout.js'),['wp-blocks','wp-element','wp-block-editor','wp-components'],'1.0.0',true);
    register_block_type('pin/source-layout',[
        'api_version'=>3,'editor_script'=>'pin-source-layout-editor','render_callback'=>'pin_source_layout_render',
        'attributes'=>['tagName'=>['type'=>'string','default'=>'div'],'sourceAttrs'=>['type'=>'object','default'=>new stdClass()]],
        'supports'=>['html'=>false,'align'=>['wide','full'],'color'=>['background'=>true,'text'=>true,'gradients'=>true],'spacing'=>['padding'=>true,'margin'=>true],'typography'=>['fontSize'=>true,'lineHeight'=>true,'__experimentalFontFamily'=>true,'__experimentalFontWeight'=>true],'__experimentalBorder'=>['color'=>true,'radius'=>true,'style'=>true,'width'=>true],'dimensions'=>['minHeight'=>true],'shadow'=>true]
    ]);
});
add_filter('body_class',static function($classes){$classes[]='pin-source-edge';return $classes;});
add_action('wp_enqueue_scripts',static function(){wp_enqueue_style('pin-source-edge',get_theme_file_uri('/source-layout.css'),[], '1.0.0');},100);
add_action('enqueue_block_assets',static function(){if(is_admin())wp_enqueue_style('pin-source-edge-editor',get_theme_file_uri('/source-layout.css'),[],'1.0.0');},100);
