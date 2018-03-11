<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Andreas Fischer <bantu@owncloud.com>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Bartek Przybylski <bart.p.pl@gmail.com>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Byron Marohn <combustible@live.com>
 * @author Christopher Schäpers <kondou@ts.unde.re>
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author j-ed <juergen@eisfair.org>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Johannes Willnecker <johannes@willnecker.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Olivier Paroz <github@oparoz.com>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Thomas Tanghus <thomas@tanghus.net>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

/**
 * Class for basic image manipulation
 */
class OC_Image implements \OCP\IImage {
	/** @var false|resource */
	protected $resource = false; // Imagine\Image\ImageInterface
	/** @var int */
	protected $imageType = IMAGETYPE_PNG; // Default to png if file type isn't evident.
	/** @var string */
	protected $mimeType = 'image/png'; // Default to png
	/** @var int */
	protected $bitDepth = 24;
	/** @var null|string */
	protected $filePath = null;
	/** @var finfo */
	private $fileInfo;
	/** @var \OCP\ILogger */
	private $logger;
	/** @var \OCP\IConfig */
	private $config;
	/** @var array */
	private $exif; // Imagine\Image\Metadata\MetadataBag

	/**
	 * Constructor.
	 *
	 * @param resource|string $imageRef The path to a local file, a base64 encoded string or a resource created by
	 * an imagecreate* function.
	 * @param \OCP\ILogger $logger
	 * @param \OCP\IConfig $config
	 * @throws \InvalidArgumentException in case the $imageRef parameter is not null
	 */
	public function __construct($imageRef = null, \OCP\ILogger $logger = null, \OCP\IConfig $config = null) {
		$this->logger = $logger;
		if ($logger === null) {
			$this->logger = \OC::$server->getLogger();
		}
		$this->config = $config;
		if ($config === null) {
			$this->config = \OC::$server->getConfig();
		}

		if (\OC_Util::fileInfoLoaded()) {
			$this->fileInfo = new finfo(FILEINFO_MIME_TYPE);
		}

		if ($imageRef !== null) {
			throw new \InvalidArgumentException('The first parameter in the constructor is not supported anymore. Please use any of the load* methods of the image object to load an image.');
		}
	}

	/**
	 * Determine whether the object contains an image resource.
	 *
	 * @return bool
	 */
	public function valid() { // apparently you can't name a method 'empty'...
		return $this->resource !== false;
	}

	/**
	 * Returns the MIME type of the image or an empty string if no image is loaded.
	 *
	 * @return string
	 */
	public function mimeType() {
		return $this->valid() ? $this->mimeType : '';
	}

	/**
	 * Returns the width of the image or -1 if no image is loaded.
	 *
	 * @return int
	 */
	public function width() {
		return $this->valid() ? $this->resource->getSize()->getWidth() : -1;
	}

	/**
	 * Returns the height of the image or -1 if no image is loaded.
	 *
	 * @return int
	 */
	public function height() {
		return $this->valid() ? $this->resource->getSize()->getHeight() : -1;
	}

	/**
	 * Returns the width when the image orientation is top-left.
	 *
	 * @return int
	 */
	public function widthTopLeft() {
		$o = $this->getOrientation();
		$this->logger->debug('OC_Image->widthTopLeft() Orientation: ' . $o, array('app' => 'core'));
		switch ($o) {
			case -1:
			case 1:
			case 2: // Not tested
			case 3:
			case 4: // Not tested
				return $this->width();
			case 5: // Not tested
			case 6:
			case 7: // Not tested
			case 8:
				return $this->height();
		}
		return $this->width();
	}

	/**
	 * Returns the height when the image orientation is top-left.
	 *
	 * @return int
	 */
	public function heightTopLeft() {
		$o = $this->getOrientation();
		$this->logger->debug('OC_Image->heightTopLeft() Orientation: ' . $o, array('app' => 'core'));
		switch ($o) {
			case -1:
			case 1:
			case 2: // Not tested
			case 3:
			case 4: // Not tested
				return $this->height();
			case 5: // Not tested
			case 6:
			case 7: // Not tested
			case 8:
				return $this->width();
		}
		return $this->height();
	}

	/**
	 * Outputs the image.
	 *
	 * @param string $mimeType
	 * @return bool
	 */
	public function show($mimeType = null) {
		if (!$this->valid()) {
			return false;
		}

		if ($mimeType === null) {
			$mimeType = $this->mimeType();
		}
		return $this->_saveOrOutput(null, $mimeType);
	}

	/**
	 * Saves the image.
	 *
	 * @param string $filePath
	 * @param string $mimeType
	 * @return bool
	 */

	public function save($filePath, $mimeType = null) {
		if (!$this->valid()) {
			return false;
		}

		if ($mimeType === null) {
			$mimeType = $this->mimeType();
		}

		if (!file_exists(dirname($filePath))) {
			mkdir(dirname($filePath), 0777, true);
		}

		try {
			$this->_saveOrOutput($filePath, $mimeType);
			return true;
		} catch (RuntimeException $e) {
			$this->logger->error(__METHOD__ . '(): Path \'' . $filePath . '\' is not writable.', array('app' => 'core'));
			return false;
		}
	}

	/**
	 * Outputs/saves the image.
	 *
	 * @param string $filePath
	 * @param string $mimeType
	 * @return bool for file showing/manipulation
	 * @throws Exception
	 */
	private function _saveOrOutput($filePath = null, $mimeType) {
		$imageType = $this->imageType;
		if ($mimeType !== null) {
			switch ($mimeType) {
				case 'image/gif':
					$imageType = IMAGETYPE_GIF;
					break;
				case 'image/jpeg':
					$imageType = IMAGETYPE_JPEG;
					break;
				case 'image/png':
					$imageType = IMAGETYPE_PNG;
					break;
				case 'image/x-xbitmap':
					$imageType = IMAGETYPE_XBM;
					break;
				case 'image/bmp':
				case 'image/x-ms-bmp':
					$imageType = IMAGETYPE_BMP;
					break;
				default:
					throw new Exception('\OC_Image::_output(): "' . $mimeType . '" is not supported when forcing a specific output format');
			}
		}

		if ($imageType === IMAGETYPE_BMP && $this->resource instanceof Imagine\Gd\Imagine) {
			return imagebmp($this->resource->getGdResource(), $this->filePath);
		}

		if ($filePath === null) {
			return $this->resource->show(null, $this->_getOptions($imageType));
		}
		return $this->resource->save($filePath, $this->_getOptions($imageType));
	}

	/**
	 * Prints the image when called as $image().
	 */
	public function __invoke() {
		return $this->show();
	}

	/**
	 * @return resource Returns the image resource if any.
	 */
	public function resource() {
		return $this->resource;
	}

	/**
	 * @return string Returns the mimetype of the data. Returns the empty string
	 * if the data is not valid.
	 */
	public function dataMimeType() {
		if (!$this->valid()) {
			return '';
		}

		switch ($this->mimeType) {
			case 'image/png':
			case 'image/jpeg':
			case 'image/gif':
				return $this->mimeType;
			default:
				return 'image/png';
		}
	}

	/**
	 * @return null|string Returns the raw image data.
	 */
	public function data() {
		if (!$this->valid()) {
			return null;
		}

		try {
			switch ($this->mimeType) {
				case "image/png":
					return $this->resource->get('png');
				case "image/gif":
					return $this->resource->get('gif');
				case "image/jpeg":
					return $this->resource->get('jpg', $this->_getOptions(IMAGETYPE_JPEG));
				default:
					$this->logger->info('OC_Image->data. Could not guess mime-type, defaulting to png', array('app' => 'core'));
					return $this->resource->get('png');
			}
		} catch (RuntimeException $e) {
			$this->logger->error('OC_Image->data. Error getting image data.', array('app' => 'core'));
			return null;
		}
	}

	/**
	 * @return string - base64 encoded, which is suitable for embedding in a VCard.
	 */
	public function __toString() {
		return base64_encode($this->data());
	}

	/**
	 * @return int|null
	 */
	protected function getJpegQuality() {
		$quality = $this->config->getAppValue('preview', 'jpeg_quality', 90);
		if ($quality !== null) {
			$quality = min(100, max(10, (int) $quality));
		}
		return $quality;
	}

	/**
	 * (I'm open for suggestions on better method name ;)
	 * Get the orientation based on EXIF data.
	 *
	 * @return int The orientation or -1 if no EXIF data is available.
	 */
	public function getOrientation() {
		if ($this->exif !== null) {
			return $this->exif['Orientation'];
		}

		if (!$this->valid()) {
			$this->logger->debug('OC_Image->fixOrientation() No image loaded.', array('app' => 'core'));
			return -1;
		}

		if ($this->imageType !== IMAGETYPE_JPEG) {
			$this->logger->debug('OC_Image->fixOrientation() Image is not a JPEG.', array('app' => 'core'));
			return -1;
		}

		$exif = $this->resource->metadata()['IFD0'];
		if (!$exif || !isset($exif['Orientation'])) {
			return -1;
		}
		$this->exif = $exif;
		return $exif['Orientation'];
	}

	/**
	 * (I'm open for suggestions on better method name ;)
	 * Fixes orientation based on EXIF data.
	 *
	 * @return bool
	 */
	public function fixOrientation() {
		$o = $this->getOrientation();
		$this->logger->debug('OC_Image->fixOrientation() Orientation: ' . $o, array('app' => 'core'));
		$rotate = 0;
		$flip = false;
		switch ($o) {
			case -1:
				return false; //Nothing to fix
			case 1:
				$rotate = 0;
				break;
			case 2:
				$rotate = 0;
				$flip = true;
				break;
			case 3:
				$rotate = 180;
				break;
			case 4:
				$rotate = 180;
				$flip = true;
				break;
			case 5:
				$rotate = 90;
				$flip = true;
				break;
			case 6:
				$rotate = 270;
				break;
			case 7:
				$rotate = 270;
				$flip = true;
				break;
			case 8:
				$rotate = 90;
				break;
		}

		try {
			$transformation = new Imagine\Filter\Transformation();
			if ($flip) {
				$transformation = $transformation->flipHorizontally();
			}
			if ($rotate !== 0) {
				$transformation = $transformation->rotate($rotate);
			}
			$this->resource = $transformation->apply($this->resource);
			$this->exif = null;
			return true;
		} catch (RuntimeException $e) {
			$this->logger->debug('OC_Image->fixOrientation() Error during orientation fixing', array('app' => 'core'));
			return false;
		}
	}

	/**
	 * Loads an image from an open file handle.
	 * It is the responsibility of the caller to position the pointer at the correct place and to close the handle again.
	 *
	 * @param resource $handle
	 * @return resource|false An image resource or false on error
	 */
	public function loadFromFileHandle($handle) {
		$contents = stream_get_contents($handle);
		return $this->loadFromData($contents);
	}

	/**
	 * Loads an image from a local file.
	 *
	 * @param bool|string $imagePath The path to a local file.
	 * @return bool|resource An image resource or false on error
	 */
	public function loadFromFile($imagePath = false) {
		// exif_imagetype throws "read error!" if file is less than 12 byte
		if (!@is_file($imagePath) || !is_readable($imagePath) || filesize($imagePath) < 12) {
			return false;
		}

		$iType = exif_imagetype($imagePath);

		try {
			if ($iType !== false) {
				$this->resource = $this->_createImagineInterface()->open($imagePath);
			} else {
				// this is mostly file created from encrypted file
				$this->resource = $this->loadFromData(\OC\Files\Filesystem::file_get_contents(\OC\Files\Filesystem::getLocalPath($imagePath)));
			}
		} catch (RuntimeException $e) {
			$this->logger->debug("OC_Image->loadFromFile could not load: $e", array('app' => 'core'));
			return false;
		}

		if ($this->valid()) {
			$this->imageType = $iType;
			$this->mimeType = image_type_to_mime_type($iType);
			$this->filePath = $imagePath;
		}
		return $this->resource;
	}

	/**
	 * Loads an image from a string of data.
	 *
	 * @param string $str A string of image data as read from a file.
	 * @return bool|resource An image resource or false on error
	 */
	public function loadFromData($str) {
		if (is_resource($str)) {
			return false;
		}

		try {
			$this->resource = $this->_createImagineInterface()->load($str);
			if ($this->fileInfo) {
				$this->mimeType = $this->fileInfo->buffer($str);
			}
			return $this->resource;
		} catch (RuntimeException $e) {
			$this->logger->debug("OC_Image->loadFromData, could not load: $e", array('app' => 'core'));
			return false;
		}
	}

	/**
	 * Loads an image from a base64 encoded string.
	 *
	 * @param string $str A string base64 encoded string of image data.
	 * @return bool|resource An image resource or false on error
	 */
	public function loadFromBase64($str) {
		if (!is_string($str)) {
			return false;
		}

		$data = base64_decode($str);
		return $this->loadFromData($data);
	}

	/**
	 * Resizes the image preserving ratio.
	 *
	 * @param integer $maxSize The maximum size of either the width or height.
	 * @return bool
	 */
	public function resize($maxSize) {
		if (!$this->valid()) {
			$this->logger->error(__METHOD__ . '(): No image loaded', array('app' => 'core'));
			return false;
		}

		$size = $this->resource->getSize();
		$ratioOrig = $size->getWidth() / $size->getHeight();

		if ($ratioOrig > 1) {
			$newHeight = round($maxSize / $ratioOrig);
			$newWidth = $maxSize;
		} else {
			$newWidth = round($maxSize * $ratioOrig);
			$newHeight = $maxSize;
		}

		return $this->preciseResize(round($newWidth), round($newHeight));
	}

	/**
	 * @param int $width
	 * @param int $height
	 * @return bool
	 */
	public function preciseResize(int $width, int $height): bool {
		if (!$this->valid()) {
			$this->logger->error(__METHOD__ . '(): No image loaded', array('app' => 'core'));
			return false;
		}

		try {
			$transformation = new Imagine\Filter\Transformation();
			$size = new Imagine\Image\Box($width, $height);
			$this->resource = $transformation->
			thumbnail($size, Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND)->
			apply($this->resource);
			return true;
		} catch (RuntimeException $e) {
			$this->logger->error(__METHOD__ . "(): Error resizing image $e", array('app' => 'core'));
			return false;
		}
	}

	/**
	 * Crops the image to the middle square. If the image is already square it just returns.
	 *
	 * @param int $size maximum size for the result (optional)
	 * @return bool for success or failure
	 */
	public function centerCrop($size = 0) {
		if (!$this->valid()) {
			$this->logger->error('OC_Image->centerCrop, No image loaded', array('app' => 'core'));
			return false;
		}

		$size = $this->resource->getSize();
		$widthOrig = $size->getWidth();
		$heightOrig = $size->getHeight();
		if ($widthOrig === $heightOrig and $size == 0) {
			return true;
		}
		$ratioOrig = $widthOrig / $heightOrig;
		$width = $height = min($widthOrig, $heightOrig);

		if ($ratioOrig > 1) {
			$x = ($widthOrig / 2) - ($width / 2);
			$y = 0;
		} else {
			$y = ($heightOrig / 2) - ($height / 2);
			$x = 0;
		}
		if ($size > 0) {
			$targetWidth = $size;
			$targetHeight = $size;
		} else {
			$targetWidth = $width;
			$targetHeight = $height;
		}

		return crop($x, $y, $targetWidth, $targetHeight);
	}

	/**
	 * Crops the image from point $x$y with dimension $wx$h.
	 *
	 * @param int $x Horizontal position
	 * @param int $y Vertical position
	 * @param int $w Width
	 * @param int $h Height
	 * @return bool for success or failure
	 */
	public function crop(int $x, int $y, int $w, int $h): bool {
		if (!$this->valid()) {
			$this->logger->error(__METHOD__ . '(): No image loaded', array('app' => 'core'));
			return false;
		}

		try {
			$transformation = new Imagine\Filter\Transformation();
			$point = new Imagine\Image\Point($x, $y);
			$size = new Imagine\Image\Box($w, $h);
			$this->resource = $transformation->crop($point, $size)->apply($this->resource);
			return true;
		} catch (RuntimeException $e) {
			$this->logger->error(__METHOD__ . "(): Error cropping image $e", array('app' => 'core'));
			return false;
		}
	}

	/**
	 * Resizes the image to fit within a boundary while preserving ratio.
	 *
	 * Warning: Images smaller than $maxWidth x $maxHeight will end up being scaled up
	 *
	 * @param integer $maxWidth
	 * @param integer $maxHeight
	 * @return bool
	 */
	public function fitIn($maxWidth, $maxHeight) {
		if (!$this->valid()) {
			$this->logger->error(__METHOD__ . '(): No image loaded', array('app' => 'core'));
			return false;
		}

		$size = $this->resource->getSize();
		$widthOrig = $size->getWidth();
		$heightOrig = $size->getHeight();
		$ratio = $widthOrig / $heightOrig;

		$newWidth = min($maxWidth, $ratio * $maxHeight);
		$newHeight = min($maxHeight, $maxWidth / $ratio);

		return $this->preciseResize((int)round($newWidth), (int)round($newHeight));
	}

	/**
	 * Shrinks larger images to fit within specified boundaries while preserving ratio.
	 *
	 * @param integer $maxWidth
	 * @param integer $maxHeight
	 * @return bool
	 */
	public function scaleDownToFit($maxWidth, $maxHeight) {
		if (!$this->valid()) {
			$this->logger->error(__METHOD__ . '(): No image loaded', array('app' => 'core'));
			return false;
		}

		$size = $this->resource->getSize();
		$widthOrig = $size->getWidth();
		$heightOrig = $size->getHeight();

		if ($widthOrig > $maxWidth || $heightOrig > $maxHeight) {
			return $this->fitIn($maxWidth, $maxHeight);
		}

		return false;
	}

	/**
	 * Return the options needed for image display/saving
	 *
	 * @param $imageType
	 * @return array
	 */
	private function _getOptions($imageType) {
		return $imageType === IMAGETYPE_JPEG ? array('jpeg_quality' => $this->getJpegQuality()) : array();
	}

	private function _createImagineInterface() {
		$generator = $this->config->getSystemValue('image_generator', null);
		if ($generator !== null) return (new ReflectionClass("Imagine\\$generator\Imagine"))->newInstance();

		return new Imagine\Gd\Imagine();
	}

	/**
	 * Destroys the current image and resets the object
	 */
	public function destroy() {
		$this->resource = null;
	}

	public function __destruct() {
		$this->destroy();
	}
}

if (!function_exists('imagebmp')) {
	/**
	 * Output a BMP image to either the browser or a file
	 *
	 * @link http://www.ugia.cn/wp-data/imagebmp.php
	 * @author legend <legendsky@hotmail.com>
	 * @link http://www.programmierer-forum.de/imagebmp-gute-funktion-gefunden-t143716.htm
	 * @author mgutt <marc@gutt.it>
	 * @version 1.00
	 * @param resource $im
	 * @param string $fileName [optional] <p>The path to save the file to.</p>
	 * @param int $bit [optional] <p>Bit depth, (default is 24).</p>
	 * @param int $compression [optional]
	 * @return bool <b>TRUE</b> on success or <b>FALSE</b> on failure.
	 */
	function imagebmp($im, $fileName = '', $bit = 24, $compression = 0) {
		if (!in_array($bit, array(1, 4, 8, 16, 24, 32))) {
			$bit = 24;
		} else if ($bit == 32) {
			$bit = 24;
		}
		$bits = pow(2, $bit);
		imagetruecolortopalette($im, true, $bits);
		$width = imagesx($im);
		$height = imagesy($im);
		$colorsNum = imagecolorstotal($im);
		$rgbQuad = '';
		if ($bit <= 8) {
			for ($i = 0; $i < $colorsNum; $i++) {
				$colors = imagecolorsforindex($im, $i);
				$rgbQuad .= chr($colors['blue']) . chr($colors['green']) . chr($colors['red']) . "\0";
			}
			$bmpData = '';
			if ($compression == 0 || $bit < 8) {
				$compression = 0;
				$extra = '';
				$padding = 4 - ceil($width / (8 / $bit)) % 4;
				if ($padding % 4 != 0) {
					$extra = str_repeat("\0", $padding);
				}
				for ($j = $height - 1; $j >= 0; $j--) {
					$i = 0;
					while ($i < $width) {
						$bin = 0;
						$limit = $width - $i < 8 / $bit ? (8 / $bit - $width + $i) * $bit : 0;
						for ($k = 8 - $bit; $k >= $limit; $k -= $bit) {
							$index = imagecolorat($im, $i, $j);
							$bin |= $index << $k;
							$i++;
						}
						$bmpData .= chr($bin);
					}
					$bmpData .= $extra;
				}
			} // RLE8
			else if ($compression == 1 && $bit == 8) {
				for ($j = $height - 1; $j >= 0; $j--) {
					$lastIndex = "\0";
					$sameNum = 0;
					for ($i = 0; $i <= $width; $i++) {
						$index = imagecolorat($im, $i, $j);
						if ($index !== $lastIndex || $sameNum > 255) {
							if ($sameNum != 0) {
								$bmpData .= chr($sameNum) . chr($lastIndex);
							}
							$lastIndex = $index;
							$sameNum = 1;
						} else {
							$sameNum++;
						}
					}
					$bmpData .= "\0\0";
				}
				$bmpData .= "\0\1";
			}
			$sizeQuad = strlen($rgbQuad);
			$sizeData = strlen($bmpData);
		} else {
			$extra = '';
			$padding = 4 - ($width * ($bit / 8)) % 4;
			if ($padding % 4 != 0) {
				$extra = str_repeat("\0", $padding);
			}
			$bmpData = '';
			for ($j = $height - 1; $j >= 0; $j--) {
				for ($i = 0; $i < $width; $i++) {
					$index = imagecolorat($im, $i, $j);
					$colors = imagecolorsforindex($im, $index);
					if ($bit == 16) {
						$bin = 0 << $bit;
						$bin |= ($colors['red'] >> 3) << 10;
						$bin |= ($colors['green'] >> 3) << 5;
						$bin |= $colors['blue'] >> 3;
						$bmpData .= pack("v", $bin);
					} else {
						$bmpData .= pack("c*", $colors['blue'], $colors['green'], $colors['red']);
					}
				}
				$bmpData .= $extra;
			}
			$sizeQuad = 0;
			$sizeData = strlen($bmpData);
			$colorsNum = 0;
		}
		$fileHeader = 'BM' . pack('V3', 54 + $sizeQuad + $sizeData, 0, 54 + $sizeQuad);
		$infoHeader = pack('V3v2V*', 0x28, $width, $height, 1, $bit, $compression, $sizeData, 0, 0, $colorsNum, 0);
		if ($fileName != '') {
			$fp = fopen($fileName, 'wb');
			fwrite($fp, $fileHeader . $infoHeader . $rgbQuad . $bmpData);
			fclose($fp);
			return true;
		}
		echo $fileHeader . $infoHeader . $rgbQuad . $bmpData;
		return true;
	}
}

