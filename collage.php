<?php

/**
 * Скрипт для генерации коллажа из двух jpeg эскизов, находящихся в папке Download
 */

// Проверяем валидность ввода
if(empty($_GET['f']) || empty($_GET['s']) || !preg_match("/^[0-9a-z_-]+$/u", $_GET['f'].$_GET['s'])) exit;

// Проверяем валидность эскизов
$img1Path = __DIR__ . '/Download/'.$_GET['f'];
$img2Path = __DIR__ . '/Download/'.$_GET['s'];
if(
	!file_exists($img1Path) 
	|| !file_exists($img2Path)
	|| !$img1 = imagecreatefromjpeg($img1Path)
	|| !$img2 = imagecreatefromjpeg($img2Path)
) exit;

// Получаем размеры картинок
$width = [1 => imagesx($img1), 2 => imagesx($img2)];
$height = [1 => imagesy($img1), 2 => imagesy($img2)];

// Обрезаем картинки до квадратов
foreach([1,2] as $i){
    if($width[$i] > $height[$i]) {
        $newx = ($width[$i]-$height[$i])/2;
		$width[$i] = $height[$i];
        ${'crop'.$i} = imagecrop(${'img'.$i}, ['x' => $newx, 'y' => 0, 'width' => $width[$i], 'height' => $height[$i]]);
    }
    elseif($height[$i] > $width[$i]){
        $newy = ($height[$i]-$width[$i])/2;
		$height[$i] = $width[$i];
        ${'crop'.$i} = imagecrop(${'img'.$i}, ['x' => 0, 'y' => $newy, 'width' => $width[$i], 'height' => $height[$i]]);
    }
	else{
		${'crop'.$i} = ${'img'.$i};
	}
}

// Определяем наименьшую сторону, к которой будут приведены квадраты на коллаже
$minSide = min($width[1], $width[2], $height[1], $height[2], 624);

// Определяем размер рамки
$padding = floor($minSide / 77);

// Определяем размеры коллажа
$target_width = $minSide * 2 + $padding * 4;
$target_height = $minSide + $padding * 2;

// Создаем коллаж
$target_image = imagecreatetruecolor($target_width, $target_height);
#$white = imagecolorallocate($target_image, 255, 255, 255);
#imagefill($target_image, 0, 0, $white);

// Копируем квадраты на коллаж
imagecopyresampled($target_image, $crop1, $padding, $padding, 0, 0, $minSinde, $minSide, $width[1], $height[1]);
imagecopyresampled($target_image, $crop2, $padding * 3 + $minSide, $padding, 0, 0, $minSide, $minSide, $width[2], $height[2]);

#imagedestroy($img1);
#imagedestroy($img2);

// Выводим результат
header('Content-Type: image/jpeg');
imagejpeg($target_image, null, 90);