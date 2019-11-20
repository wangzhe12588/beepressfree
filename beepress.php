<?php

/*

Plugin Name: BeePress

Plugin URI: http://artizen.me/beepress

Description: 微信公众号文章一键导入，自动采集

Version: 111.4.3

Author: 黄碧成（Bee）

Author URI: http://artizen.me

License: GPL

*/



/**

 * 初始化

 */

if(!class_exists('simple_html_dom_node')){

	require_once("simple_html_dom.php");

}



$GLOBALS['errMsg'] = array();

$GLOBALS['done'] = false;



add_action('admin_init', 'beepress_admin_init');

add_action('init', 'beepress_process_request');







global $beepress_cron_table, $table_prefix;

$beepress_cron_table = $table_prefix.'bp_cron_config';



function beepress_admin_init () {

	// 引入样式文件及交互脚本

	wp_register_style('bootstrap-style', plugins_url('/vender/bootstrap/css/bootstrap.min.css', __FILE__));

	wp_register_script('bootstrap-script', plugins_url('/vender/bootstrap/js/bootstrap.min.js', __FILE__));

}



/**

 * 后台入口

 */

if (is_admin()) {

	add_action('admin_menu', 'beepress_admin_menu');

}

// 在文章下面添加子菜单入口

function beepress_admin_menu() {

	add_menu_page('「BeePress｜蜜蜂采集」，文章一键导入插件', 'BeePress', 'publish_posts', 'beepress', 'beepress_setting_page', '');

}

// BeePress界面

function beepress_setting_page() {

	require_once 'setting-page.php';

}



// 处理请求

function beepress_process_request() {

	set_time_limit(0);//避免超时

	global $wpdb;

	global $beepress_cron_table;

	// 媒体：wx 公众号, js 简书

	$schedule = isset($_REQUEST['schedule']) ? intval($_REQUEST['schedule']) == 1 : false;

	$plugin   = isset($_REQUEST['plugin']) ? intval($_REQUEST['plugin']) == 1 : false;

	$media    = isset($_REQUEST['media']) ? $_REQUEST['media'] : '';

	$setting  = isset($_REQUEST['setting']) ? $_REQUEST['setting'] : '';



	$conf     = $wpdb->get_row("SELECT * FROM $beepress_cron_table", ARRAY_A);

	if ($setting == 'cron') {

		$token = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';

		$open  = isset($_REQUEST['open']) ? $_REQUEST['open'] : '';

		$open  = intval($open == 'on' ? 1 : 0);



		if ($conf) {

			$id = $conf['id'];

			$sql = "UPDATE " . $beepress_cron_table . " SET token='$token', open=$open " . " WHERE id=$id";

		} else {

			$sql = "INSERT INTO " . $beepress_cron_table . " VALUES(1, '$token', $open)";

		}

		$wpdb->query($sql);

		return;

	}

	$postFile = '';

	if ($schedule) {

		// 判断token是否符合

		$token = isset($_REQUEST['token']) ? $_REQUEST['token'] : '';

		if ($conf && ($conf['token'] != $token || !intval($conf['open']) == 1)) {

			exit;

		}

		$postUrls = explode('|', base64_decode(isset($_REQUEST['urls']) ? str_replace(" ","+",$_GET['urls']) : ''));

		$media = 'wx';

	} elseif($plugin) {

		// 判断token是否符合

		$token = isset($_REQUEST['token']) ? $_REQUEST['token'] : '';

		if ($conf && ($conf['token'] != $token || !intval($conf['open']) == 1)) {

			exit;

		}

		$postUrls = isset($_REQUEST['post_urls']) ? $_REQUEST['post_urls'] : '';

		$media = 'wx';

	} else {

		// 文章url

		$postUrls = isset($_REQUEST['post_urls']) ? $_REQUEST['post_urls'] : '';

		$postFile = isset($_FILES['post_file']) ? $_FILES['post_file'] : '';

	}

	$debug    = isset($_REQUEST['debug']) ? $_REQUEST['debug'] : false;

	// 两者都没有，则不进行处理

	if (!($postFile || $postUrls)) {

		return;

	}

	// 如果是文件形式，则处理成URL

	if (isset($postFile['tmp_name']) && $postFile['tmp_name']) {

		$postUrls = file_get_contents($postFile['tmp_name']);

	}

	$finalUrls = $schedule ? $postUrls : explode("\n", $postUrls);

	if (count($finalUrls) == 0) {

		$GLOBALS['errMsg'][] = '没有符合要求的文章地址';

		return;

	}



	$postId = null;

	switch($media) {

		// 微信

		case 'wx':

			$postId = beepress_for_wx_insert_by_url($finalUrls);

			break;

		// 简书 todo

		case 'js':

			break;

		default:

			// do nothing

			break;

	}

	if ($debug == 'debug') {

		var_dump($GLOBALS['errMsg']);

		exit;

	}

	if ($schedule || $plugin) {

		exit;

	}



	if ($postId && count($finalUrls) == 1) {

		$editPostUrl = home_url('wp-admin/post.php?post=' . $postId . '&action=edit');

		wp_redirect($editPostUrl);

	}

}



function beepress_for_wx_insert_by_url($urls) {

	//添加下载图片地址到本地功能

	$schedule       = isset($_REQUEST['schedule']) && intval($_REQUEST['schedule']) == 1;

	$sprindboard    = isset($_REQUEST['springboard']) ?

						$_REQUEST['springboard'] :

						'http://read.html5.qq.com/image?src=forum&q=4&r=0&imgflag=7&imageUrl=';

	// 微信原作者

	$changeAuthor   = false;

	// 改变发布时间

	$changePostTime = isset($_REQUEST['change_post_time']) && $_REQUEST['change_post_time'] == 'true';

	// 默认是直接发布

	$postStatus     = isset($_REQUEST['post_status']) && in_array($_REQUEST['post_status'], array('publish', 'pending', 'draft')) ?

						$_REQUEST['post_status'] : 'publish';

	// 保留文章样式

	$keepStyle      = isset($_REQUEST['keep_style']) && $_REQUEST['keep_style'] == 'keep';

	// 文章分类，默认是未分类（1）

	$postCate       = isset($_REQUEST['post_cate']) ? intval($_REQUEST['post_cate']) : 1;

	$postCate       = array($postCate);

	// 文章类型，默认是post

	$postType       = isset($_REQUEST['post_type']) ? $_REQUEST['post_type'] : 'post';

//	$debug          = isset($_REQUEST['debug']) ? $_REQUEST['debug'] : false;

	$force          = isset($_REQUEST['force']) ? $_REQUEST['force'] : false;



	$postId         = null;

	$urls           = str_replace('https', 'http', $urls);

	foreach ($urls as $url) {

		// 过滤不符合规范的URL

		if (strpos($url, 'http://mp.weixin.qq.com/s') !== false || strpos($url, 'https://mp.weixin.qq.com/s') !== false) {

			$url =  trim($url);

		}

		if (!$url) {

			continue;

		}

		if (function_exists('file_get_contents')) {

			$html = @file_get_contents($url);

		} else {

			$GLOBALS['errMsg'][] = '不支持file_get_contents';

			break;

		}

		if ($html == '') {

			$ch = curl_init();

			$timeout = 30;

			curl_setopt($ch, CURLOPT_URL, $url);

			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

			$html = curl_exec($ch);

			curl_close($ch);

		}

		if (!$html) {

			$GLOBALS['errMsg'][] = array(

				'url' => $url,

				'msg' => '无法获取此条URL内容'

			);

			continue;

		}

		// 是否移除原文样式

		if (!$keepStyle) {

			$html = preg_replace('/style\=\"[^\"]*\"/', '', $html);

		}

		$dom  = str_get_html($html);

		// 文章标题

		$title   = $dom->find('#activity-name', 0)->plaintext;

		$title   = trim($title);

		// 确保有标题

		if (!$title) {

			$GLOBALS['errMsg'][] = array(

				'url' => $url,

				'msg' => '此条URL没有文章标题'

			);

			continue;

		}

		// 检查标题是否重复，若重复则跳过

		if ($id = post_exists($title) && !$force) {

			$GLOBALS['errMsg'][] = array(

				'url' => $url,

				'msg' => '标题重复'

			);

			continue;

		}

		// 处理图片及视频资源

		$imageDoms = $dom->find('img');

		$videoDoms = $dom->find('.video_iframe');

		foreach ($imageDoms as $imageDom) {

			$dataSrc = $imageDom->getAttribute('data-src');

			if (!$dataSrc) {

				continue;

			}

			$src  = $sprindboard . $dataSrc;

			$imageDom->setAttribute('src', $src);

		}

		foreach ($videoDoms as $videoDom) {

			$dataSrc = $videoDom->getAttribute('data-src');

			// 视频不用跳板

			$videoDom->setAttribute('src', $dataSrc);

		}

		// 发布日期

		if ($changePostTime) {

			$postDate = date('Y-m-d H:i:s', time());

		} else {

			$postDate = $dom->find('#post-date', 0)->plaintext;

			$postDate = date('Y-m-d H:i:s', strtotime($postDate));

		}

		// 提取用户信息

		$url      = parse_url($url);

		$query    = $url['query'];

		$queryArr = explode('&', $query);

		$bizVal   = '';

		$cates = array();

		foreach ($queryArr as $item) {

			list($key, $val) = explode('=', $item, 3);

			if ($key == '__biz') {

				//  用户唯一标识

				$bizVal = $val;

			}

			if ($key == 'cates') {

				$cates = explode(',', $val);

			}

		}

		// 如果链接中不含有biz参数，则选择当前的时间戳作为用户名和密码

		if ($bizVal == '') {

			$bizVal = time();

		}



		// 是否改变作者，默认是当前登录作者

		$userName = $dom->find('#post-user', 0)->plaintext;

		$userName = esc_html($userName);

		if ($changeAuthor) {

			// 创建用户

			$userId   = wp_create_user($bizVal, $bizVal);

			// 用户已存在

			if ($userId) {

				if ($userId->get_error_code() == 'existing_user_login') {

					$userData = get_user_by('login', $bizVal);

				} else if(is_integer($userId) > 0) {

					$userData = get_userdata($userId);

				} else {

					// 错误情况

					continue;

				}

				// 默认是投稿者

				$userData->add_role('contributor');

				$userData->remove_role('subscriber');

				$userData->display_name = $userName;

				$userData->nickname     = $userName;

				$userData->first_name   = $userName;

				wp_update_user($userData);

				$userId = $userData->ID;

			} else {

				// 默认博客作者

				$userId = get_current_user_id();

			}

		} else {

			// 默认博客作者

			$userId = get_current_user_id();

		}



		if ($schedule) {

			$userId = 1;

			if ($cates) {

				$cateIds = array();

				foreach ($cates as $cate) {

					$term = get_term_by('name', $cate, 'category');

					if ($term) {

						$cateIds[] = $term->term_id;

					} else {

					}

				}

				$postCate = $cateIds;

			}

		}



		$post = array(

			'post_title'    => $title,

			'post_content'  => "",

			'post_status'   => $postStatus,

			'post_date'     => $postDate,

			'post_modified' => $postDate,

			'post_author'   => $userId,

			'post_category' => $postCate,

			'post_type'	    => $postType

		);

		$postId = @wp_insert_post($post);

		// 下载图片到本地

		beepress_downloadImage($postId, $dom);

	}

	$GLOBALS['done'] = true;

	return $postId;

}



require_once( ABSPATH . 'wp-admin/includes/file.php' );

require_once( ABSPATH . 'wp-admin/includes/image.php' );

require_once( ABSPATH . 'wp-admin/includes/media.php' );

require_once( ABSPATH . 'wp-admin/includes/post.php' );



function beepress_downloadImage($postId, $dom) {

	// 提取图片

	$images            = $dom->find('img');

	$urlMode           = isset($_REQUEST['image_url_mode']) ? $_REQUEST['image_url_mode'] : 'default';

	$hasSetFeaturedImg = false;

	$schedule          = isset($_REQUEST['schedule']) && intval($_REQUEST['schedule']) == 1;

	$version           = '2-3-0';

	// 文章标题

	$title             = $dom->find('#activity-name', 0)->plaintext;

	$title             = trim($title);

	foreach ($images as $image) {

		$src  = $image->getAttribute('src');

		$type = $image->getAttribute('data-type');

		if (!$src) {

			continue;

		}

		if (strstr($src, 'res.wx.qq.com')) {

			continue;

		}

		$src = preg_replace('/^\/\//', 'http://', $src, 1);

		if (!$type) {

			$type = 'jpeg';

		}

		$tmpFile = download_url($src);

		if ($schedule) {

/*			$fileName = 'beepress-image-schedule-' . $version . '-' . $postId . '-' . time() .'.' . $type;

*		} else {

			$fileName = 'beepress-image-' . $version . '-' . $postId . '-' . time() .'.' . $type;
 */

          $fileName = 'ippdd-image-schedule-' . $version . '-' . $postId . '-' . time() .'.' . $type;

		} else {

			$fileName = 'ippdd-image-' . $version . '-' . $postId . '-' . time() .'.' . $type;

		}

		$fileArr = array(

			'name' => $fileName,

			'tmp_name' => $tmpFile

		);



		$id = @media_handle_sideload($fileArr, $postId);

		if (is_wp_error($id)) {

			$GLOBALS['errMsg'][] = array(

				'src'  => $src,

				'file' => $fileArr,

				'msg'  => $id

			);

			@unlink($tmpFile);

			continue;

		} else {

			$imageInfo = wp_get_attachment_image_src($id, 'full');

			$src    = $imageInfo[0];

			$width  = intval($imageInfo[1]);

			$height = intval($imageInfo[2]);

			$ratio  = $width / $height;

			if ($width >= 400 && $ratio <= 2 && $ratio >= 1 && !$hasSetFeaturedImg) {

				@set_post_thumbnail($postId, $id);

				$hasSetFeaturedImg = true;

			}

			$homeUrl = home_url();

			if ($urlMode == 'default') {

				$src = substr_replace($src, '', 0, strlen($homeUrl));

			}

			$image->setAttribute('src', $src);

			$image->setAttribute('alt', $title);

			$image->setAttribute('title', $title);

		}

	}

	$userName = $dom->find('#post-user', 0)->plaintext;

	$userName = esc_html($userName);

	// 保留来源

	$keepSource     = isset($_REQUEST['keep_source']) && $_REQUEST['keep_source'] == 'keep';

	$content = $dom->find('#js_content', 0)->innertext;

	$content = preg_replace('/data\-([a-zA-Z0-9\-])+\=\"[^\"]*\"/', '', $content);

	$content = preg_replace('/src=\"(http:\/\/read\.html5\.qq\.com)([^\"])*\"/', '', $content);

	$content = preg_replace('/class=\"([^\"])*\"/', '', $content);

	$content = preg_replace('/id=\"([^\"])*\"/', '', $content);

	if ($keepSource) {

		if ($schedule) {

			$source =

					"<blockquote class='keep-source'>" .

					"<p>始发于微信公众号：{$userName}</p>" .

					"</blockquote>";

		} else {

			$source =

					"<blockquote class='keep-source'>" .

					"<p>始发于微信公众号：{$userName}</p>" .

					"<p>通过<a href='http://artizen.me/beepress' target='_blank'>「BeePress｜微信公众号文章采集」WordPress 插件生成</a></p>" .

					"</blockquote>";

		}

		$content .= $source;

	}

	// 保留文章样式

	$keepStyle      = isset($_REQUEST['keep_style']) && $_REQUEST['keep_style'] == 'keep';

	//todo 过滤有问题

//	if (!$keepStyle) {

//		$content = beepress_remove_useless_tags($content);

//	}

	$content = trim($content);

	@wp_update_post(array(

		'ID' => $postId,

		'post_content' =>  $content

	));

}



function beepress_remove_useless_tags($content)

{

	$content = preg_replace("'<\s*[section|strong][^>]*[^/]>'is", '', $content);

	$content = preg_replace("'<\s*/\s*section\s*>'is", '', $content);

	$content = preg_replace("'<\s*/\s*strong\s*>'is", '', $content);

	return $content;

}





function beepress_install() {

	global $wpdb;

	global $beepress_cron_table;

	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

	if ($wpdb->get_var("SHOW TABLES LIKE '$beepress_cron_table'") != $beepress_cron_table) {

		$sql = "CREATE TABLE " . $beepress_cron_table . "(

				id SMALLINT(5) UNSIGNED NOT NULL AUTO_INCREMENT,

				token CHAR(200),

				open TINYINT(1) NOT NULL DEFAULT 1,

				PRIMARY KEY(id)

		) COLLATE='utf8_unicode_ci' ENGINE=MyISAM";

		dbDelta($sql);

	}

}



function beepress_uninstall() {

}



register_activation_hook(__FILE__, 'beepress_install');



register_uninstall_hook(__FILE__, 'beepress_uninstall');

