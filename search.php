<?php

/*
// Alkaline
// Copyright (c) 2010-2012 by Budin Ltd. Some rights reserved.
// http://www.alkalineapp.com/
*/

require_once('config.php');
require_once(PATH . CLASSES . 'alkaline.php');

if(isset($_REQUEST['type'])){
	$type = $_REQUEST['type'];
	if($type == 'posts'){
		$ids = new Find('posts');
		$ids->sort('posts.post_published', 'DESC');
		$ids->published();
	}
	else{
		$type = 'images';
		$ids = new Find('images');
		$ids->sort('images.image_published', 'DESC');
		$ids->published();
		$ids->privacy('public');
	}
	$ids->find();
	$ids->saveMemory();
	
	$_SESSION['alkaline']['search']['table'] = $type;
	
	header('Location: ' . LOCATION . BASE . 'results' . URL_CAP);
	exit();
}

$alkaline = new Alkaline;
$alkaline->recordStat('home');

$header = new Canvas;
$header->load('header');
$header->setTitle('Search');
$header->display();

$content = new Canvas;
$content->load('search');
$content->assign('EXIF_Names', $alkaline->showEXIFNames('exif_name'));
$content->assign('Rights', $alkaline->showRights('rights'));
$content->display();

$footer = new Canvas;
$footer->load('footer');
$footer->display();

?>