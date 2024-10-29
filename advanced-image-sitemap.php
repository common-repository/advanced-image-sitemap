<?php
/*
 * Plugin Name: Advanced Image Sitemap
 * Plugin URI: http://www.makong.kiev.ua/plugins/ais
 * Description: Most advanced plugin for Image Sitemap generator up-to-date. Boost your website indexation in Google (and other) Search Engines.
 * Version: 1.2
 * Author: makong
 * Author URI: http://www.makong.kiev.ua
 * License: GPL2
 */
 
register_activation_hook( __FILE__, 'ais_install');
load_plugin_textdomain( 'ais', '', dirname( plugin_basename( __FILE__ ) ) . '/languages' );

/*actions*/
add_action('admin_menu', 'ais_admin_page');
add_action('wp_ajax_ais_generate', 'ajax_ais_generate');
add_action('wp_ajax_ais_remove', 'ajax_ais_remove');

function ais_install(){
    
    $ais_options = array(
        'sizes' 	=> array(),
        'tags' 		=> array(),
		'ctags'		=> array(),
        'exclude' 	=> array(
            'bysize' => array(
                'width' 	=> 50,
                'height' 	=> 50
            ),
            'byplug' => 'on'
        ),
        'date'		=> ''
    );
    
    add_option('ais_options', array());
}

function ais_admin_page(){
    
    global $hook, $ais_image_sizes;

    $hook = add_options_page('ais', 'AIS', 8, 'ais', 'ais_page');
    $ais_image_sizes = ais_get_image_sizes();
}

function ais_get_image_sizes( $size = '' ) {
    
    global $_wp_additional_image_sizes;
    $sizes = array();
    $get_intermediate_image_sizes = get_intermediate_image_sizes();
    foreach( $get_intermediate_image_sizes as $_size ) {
        if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ) ) ) {
            $sizes[ $_size ]['width'] = get_option( $_size . '_size_w' );
            $sizes[ $_size ]['height'] = get_option( $_size . '_size_h' );
            $sizes[ $_size ]['crop'] = (bool) get_option( $_size . '_crop' );
        } elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
            $sizes[ $_size ] = array( 
                'width' => $_wp_additional_image_sizes[ $_size ]['width'],
                'height' => $_wp_additional_image_sizes[ $_size ]['height'],
                'crop' =>  $_wp_additional_image_sizes[ $_size ]['crop']
            );
        }
    }
    if ( $size ) {
        if( isset( $sizes[ $size ] ) ) {
            return $sizes[ $size ];
        } else {
            return false;
        }
    }
    return $sizes;
}

function ais_get_urls(){
	
	global $wpdb;
	$urls 	= array(home_url());
	$turls 	= array();
	$purls 	= array();
	
	foreach( @array_merge(get_post_types(array('public' => true, '_builtin' => false)), array('post', 'page')) as $pt ){
		/* archive urls */
		$purls[] = get_post_type_archive_link($pt);
		/* single urls */
		if($posts = $wpdb->get_col("SELECT ID FROM $wpdb->posts WHERE post_type = '$pt' AND post_status = 'publish'")){
			foreach($posts as $pid) if($purl = get_permalink($pid)) $purls[] = $purl;
		}
	}
	/* terms urls */
	foreach(get_taxonomies(array('public' => true)) as $tax){
		if($terms = get_terms(array('taxonomy' => $tax, 'hide_empty' => false))){
			foreach($terms as $term){
				$turl = get_term_link($term, $tax);
				if(!is_wp_error($turl)) $turls[] = $turl;
			} 
		}
	}
	
	$urls = array_merge($urls, $purls);
	$urls = array_merge($urls, $turls);
	
	return array_unique($urls);
}

function ais_get_images($urls = array()){
		
	$curl = new Zebra_cURL();
	$curl->cache('cache', 3600);
	$options = get_option('ais_options');
	$tags = implode( '|', array_merge(array('alt', 'title', 'src'), (array)$options['ctags']) );
	$images = array();
	
	if(!empty($urls)){
		foreach($urls as $url){
			$html = $curl->scrap($url);
			/*@preg_match_all('~https?://[^/\s]+/\S+\.(jpe?g|png|gif|[tg]iff?|svg)~i', $html->body, $matches);*/
			if(@preg_match_all('/<img[^>]+>/i', $html->body, $result)){
				foreach( $result as $imgs ){
					foreach( $imgs as $i => $img){
						if(@preg_match_all('/('.$tags.')="([^"]*)"/i',$img, $match)){
							foreach($match[1] as $k => $tag){
								$images[$url][$i][$tag] = $match[2][$k]; 
							}
						}
						
					}
				}
			}
		}
	}

	return $images;
}

function ais_get_xml($images = array()){

    $xml 	 = '';
	$options = get_option('ais_options');
	$tags = array_merge(array('alt', 'title'), (array)$options['ctags']);
	
	if(!empty($images)){
               
        $xml .= '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<!-- generated="'. date("d/m/Y H:i:s") .'" -->'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        
        foreach($images as $url => $imgs){
            $xml .= "<url>\n<loc>$url</loc>\n";
            foreach($imgs as $k => $img){
				
				if( ais_exclude_check($img['src'], $options) ){
					
					$xml .= "<image:image>\n";
                    $xml .= "<image:loc>". htmlspecialchars($img['src']) ."</image:loc>\n";
                    foreach(array_filter($options['tags']) as $tname => $tvalue){
						
						if(@preg_match_all('/\%(.*)\%/', $tvalue, $matches)){
							foreach($matches[0] as $j => $tag){
								$tvalue = str_ireplace($tag, (string)$img[mb_strtolower($matches[1][$j], 'UTF-8')], $tvalue);
							}
						}
                        $xml .= "<image:$tname>" . $tvalue . "</image:$tname>\n";
                    }
                    $xml .= "</image:image>\n";
				}
            }
            $xml .= "</url>\n";
        }
        $xml .= "\n</urlset>";
    }

    return $xml;
}

function ajax_ais_generate(){
		
    if(function_exists('current_user_can') && !current_user_can('manage_options') ) 
        wp_die();
    
    if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'ajax_ais_generate_nonce' ) )
        wp_die();
	
	include_once( plugin_dir_path( __FILE__ ) . 'includes/zcurl.php' );
		
	$urls 	 = ais_get_urls();
	$images  = ais_get_images($urls);
	$xml 	 = ais_get_xml($images);
	
    if($xml){

		$ais_options = get_option('ais_options');
        $file = '%s/sitemap-image.xml';
        $sitemap_path = sprintf($file, $_SERVER["DOCUMENT_ROOT"]);
		echo $sitemap_path;
		
        if(ais_is_writable($_SERVER["DOCUMENT_ROOT"]) && ais_is_writable($sitemap_path)) {
            if(file_put_contents($sitemap_path, $xml)) {
                          
                $ais_options['date'] = date("d/m/Y H:i:s");
                update_option('ais_options', $ais_options);
                
            }else{
                wp_send_json_error( array( 'error' => __( 'Failure! Cannot save XML', 'ais' ) ) );
            }
        }else{
            wp_send_json_error( array( 'error' => __( 'Failure! Directory isn\'t writable', 'ais' ) ) );
        }
    }else{
        wp_send_json_error( array( 'error' => __( 'Failure! Cannot create XML', 'ais' ) ) );
    }
}

function ais_get_page_templates(){
    
    global $wpdb;
   
    $templs = $wpdb->get_results("SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_page_template' ");
    
    foreach($templs as $templ){
        $data[] = $templ->meta_value;
    }
    
    return $data;
}

/*
function ais_imagename($img){
	$fileinfo = @pathinfo($img);
	$filename = @preg_replace('/\S\d{1,9}x\d{1,9}\S/', '', $fileinfo['filename']);
	
	return ucwords(str_replace(array('-', '_'), ' ', $filename))
}
*/

function ais_exclude_check($img, $options){
	
	$sizes = array_values($options['sizes']);
	$plugins_url = plugins_url();
	
	if(!$img){
		return false;
	}
	@preg_match('/\d{1,9}x\d{1,9}/', $img, $match);
	if($match[0] && in_array($match[0], $sizes)){
		return false;
	}
	if( 'on' == $options['exclude']['byplug'] && strpos($img, $plugins_url) ){
		return false;
	}
			
    return true;
}

function ais_xml_entities($xml) {
    return str_replace(array('&', '<', '>', '\'', '"'), array('&amp;', '&lt;', '&gt;', '&apos;', '&quot;'), $xml);
}

function ais_is_writable($filename) {
    
    if(!is_writable($filename)) {
        if(!@chmod($filename, 0666)) {
            $pathtofilename = dirname($filename);
            if(!is_writable($pathtofilename)) {
                if(!@chmod($pathtoffilename, 0666)) {
                    return false;
                }
            }
        }
    }
    
    return true;
}

function ais_last_modified(){
    
    $ais_options = get_option('ais_options');
    $file = '%s/sitemap-image.xml';
   
    if($ais_options['date'] && file_exists(sprintf($file, ABSPATH))){
        
        printf( '%1$s %2$s <a href="%3$s" target="_blank">%3$s</a> <a href="#" id="remove_xml" title="%4$s">%4$s</a>', 
            __('Last modify', 'ais'), $ais_options['date'], sprintf($file, get_bloginfo('url')), __('Remove XML', 'ais')
        );
    }
}

function ajax_ais_remove(){
    
    if(function_exists('current_user_can') && !current_user_can('manage_options') ) 
        wp_die();
    
    if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'ajax_ais_remove_nonce' ) )
        wp_die();
        
    $ais_options = get_option('ais_options');
    
    if(@unlink(ABSPATH.'/sitemap-image.xml')){
        unset($ais_options['date']);
        update_option('ais_options', $ais_options);
    }else{
        wp_send_json_error( array( 'error' => __( 'Failure! Cannot remove XML', 'ais' ) ) );
    }
}

function ais_allowed_tags($ctags){
	
	function format($tag){
		return '%' . mb_strtoupper($tag, 'UTF-8') . '%';
	}
	
	$tags = array_merge(array('alt', 'title'), (array)$ctags);
	$tags = array_map('format', $tags);
	
	return implode(', ', $tags);
}

function ais_page(){
    
    global $hook, $ais_image_sizes;
    if($hook): 
        
        if(isset($_POST['ais_settings_btn'])){
            
            if(function_exists('current_user_can') && !current_user_can('manage_options') ) 
                wp_die();
            if (function_exists ('check_admin_referer') ) 
                check_admin_referer($_POST['action'].'_form');
                          
            update_option('ais_options', array(
                'sizes' => $_POST['sizes'],
                'tags' => array_map('sanitize_text_field', $_POST['tags']),
				'ctags' => array_map('sanitize_text_field', @explode(',', $_POST['ctags'])),
                'exclude' => array(
                    'bysize' => array(
                        'width' => absint($_POST['exclude']['bysize']['width']),
                        'height' => absint($_POST['exclude']['bysize']['height'])
                    ),
                    'byplug' => $_POST['exclude']['byplug']
                ),
                'date' => ''
            ));
        }
        $ais_options = get_option('ais_options');?>
        
        <style>
            #preloader { display: none; vertical-align: middle; margin: -4px 0 0 10px ; width: 28px; height: 28px;
			background: transparent url('<?php echo plugins_url( 'images/loading.gif', plugin_basename( __FILE__ ) );?>') no-repeat center; background-size: 100%; }
            #remove_xml{ display: inline-block; vertical-align: baseline; text-indent: -9999px; width: 16px; height: 16px;
			background: transparent url('<?php echo plugins_url( 'images/remove.png', plugin_basename( __FILE__ ) );?>') no-repeat center;  background-size: auto 100%;}
			ul.ais-errors li{ display: inline-block; padding: 3px 10px; color: #b94a48; background-color: #f2dede; border: 1px solid #eed3d7; border-radius: 3px; }
			.ais-donate{float:right;padding:20px}.ais-donate img{width:180px;}
        </style>
        
        <script>
            jQuery(document).ready(function($){
                $('#ais_generate_btn').on('click touchstart', function(e) {
                    $('#preloader').css('display', 'inline-block');
                    $('.ais-progress-container').css('display', 'block');
					$.post('<?= admin_url('admin-ajax.php')?>', {
						action: 'ais_generate',
                        _wpnonce : '<?php echo wp_create_nonce('ajax_ais_generate_nonce');?>',
					}, function(response) {
						$('#preloader').css('display', 'none');
						if( typeof response === 'object' && typeof response.data.error !== 'undefined' ) {
							$('.ais-errors').append('<li>' + response.data.error + '</li>');
						}else{
							location.reload();
						}
					});
                });
                $('#remove_xml').on('click touchstart', function(e) {
					e.preventDefault();
					
					$.post('<?= admin_url('admin-ajax.php')?>', {
						action: 'ais_remove',
                        _wpnonce : '<?php echo wp_create_nonce('ajax_ais_remove_nonce');?>',
					}, function(response) {
						console.log(response);
						$('#modified').css('display', 'none');
						alert('<?php _e('XML successfully removed!')?>');
					});
                });
            });
        </script>
		<div class="row">
		
			<div class="ais-donate">
				<a href="https://www.paypal.me/MaxKondrachuk" target="_blank">
					<img src="<?= plugins_url( 'images/donate.png', plugin_basename( __FILE__ ) )?>">
				</a>
			</div>
			
			<h2><?php _e('Advanced Image Sitemap','ais');?></h2>
			
			<form name="ais_settings_form" method="post" action="<?php echo $_SERVER['PHP_SELF']?>?page=ais">
				<?php if (function_exists ('wp_nonce_field') ) wp_nonce_field('ais_settings_form');?>
				<input type="hidden" name="action" value="ais_settings"/>

				<p>
					<input type="button" id="ais_generate_btn" name="ais_generate_btn" class="button button-primary" value="<?php _e('Generate Image Sitemap','ais')?>">
					<span id="preloader"></span>
					<ul class="ais-errors"></ul>
				</p>
				<p id="modified">
					<?php if (function_exists('ais_last_modified')) ais_last_modified();?>
				</p>            
				
				<div class="card pressthis">

					<h3 class="title"><?php _e('Image XML Tags','ais')?></h3>
					<p><?php _e('The additional tags that will be presented in the generated xml file','ais')?></p>
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row"><?php _e('Title','ais')?></th>
								<td><input type="text" name="tags[title]" value="<?php echo $ais_options['tags']['title']?>" style="width:100%;">
								<p class="description"><small><?php _e('The title of image.','ais')?></small></p></td>
							</tr>
							<tr>
								<th scope="row"><?php _e('Caption','ais')?></th>
								<td><input type="text" name="tags[caption]" value="<?php echo $ais_options['tags']['caption']?>" style="width:100%;">
								<p class="description"><small><?php _e('The caption of the image. For example: %ALT% by Example.com','ais')?></small></p></td>
							</tr>
							<tr>
								<th scope="row"><?php _e('Geo Location','ais')?></th>
								<td><input type="text" name="tags[geo_location]" value="<?php echo $ais_options['tags']['geo_location']?>" style="width:100%;">
								<p class="description"><small><?php _e('The geographic location of the image. For example: Limerick, Ireland','ais')?></small></p></td>
							</tr>
							<tr>
								<th scope="row"><?php _e('License','ais')?></th>
								<td><input type="text" name="tags[license]" value="<?php echo $ais_options['tags']['license']?>" style="width:100%;">
								<p class="description"><small><?php _e('A URL to the license of the image','ais')?></small></p></td>
							</tr>
							<tr>
								<td colspan="2">
									<p class="description"><?php printf(__('Allowed tags: %s.', 'ais'), ais_allowed_tags($ais_options['ctags']));?></p>
									<p class="description"><small><?php _e('If you want to use custom image tags (in addition to the existing: alt, title), please enter theme, comma-separeted.','ais')?></small></p>
									<p><input type="text" name="ctags" value="<?php echo @implode(', ', $ais_options['ctags'])?>" style="width:100%;"></p>
								</td>
							</tr>
						</tbody>
					</table>
					
					<p><hr></p>
					<h3 class="title"><?php _e('Exclude Images','ais')?></h3>
				   
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row"><?php _e('Image Sizes','ais')?></th>
								<td>
									<?php if(!empty($ais_image_sizes)):?>
										<table>
											<tbody>
												<?php foreach($ais_image_sizes as $size => $params): if($params['width'] != 0 and $params['height'] != 0):
													$value = $params['width'] . 'x' . $params['height'];
													$check = ($value === @$ais_options['sizes'][$size]) ? 'checked' : '';?>
													<tr>
														<th scope="row">
															<input type="checkbox" name="sizes[<?php echo $size?>]" value="<?= $value?>" <?php echo $check?> />
															&nbsp;<?php echo ucwords(str_replace('-', ' ', $size))?>
														</th>
														<td><?php printf(__('width: %1$d | height: %2$d','ais'),$params['width'], $params['height'])?></td>
													</tr>
												<?php endif; endforeach;?>
											</tbody>
										</table>
									<?php endif;?>
									<p class="description">
										<small><?php _e('The sizes listed below will be excluded from the generated xml file','ais')?></small>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php _e('Used in plugins','ais')?></th>
								<?php $check = ('on' === $ais_options['exclude']['byplug']) ? 'checked' : '';?>
								<td>
									<input type="checkbox" name="exclude[byplug]" <?php echo $check?>>
									<p class="description">
										<small><?php _e("Pictures found in folders of WP plugins won't be included into Image Sitemap.",'ais')?></small>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
					<p><input type="submit" name="ais_settings_btn" class="button button-primary" value="<?php _e('Update Settings','ais')?>"></p>
				</div>
			</form>
        </div>
    <?php endif;
}