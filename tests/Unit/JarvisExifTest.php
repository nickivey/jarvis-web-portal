<?php
use PHPUnit\Framework\TestCase;

final class JarvisExifTest extends TestCase {
  public function testGpsToDecimalSimple() {
    $in = ['52/1','30/1','0/1'];
    $d = jarvis_exif_gps_to_decimal($in);
    $this->assertIsFloat($d);
    $this->assertGreaterThan(52.4, $d);
    $this->assertLessThan(52.6, $d);
  }

  public function testParseGpsArray() {
    $exif = [ 'GPS' => [ 'GPSLatitude' => ['52/1','30/1','1234/100'], 'GPSLatitudeRef' => 'N', 'GPSLongitude' => ['13/1','24/1','0/1'], 'GPSLongitudeRef' => 'E' ] ];
    $g = jarvis_exif_get_gps($exif);
    $this->assertIsArray($g);
    $this->assertArrayHasKey('lat',$g);
    $this->assertArrayHasKey('lon',$g);
  }

  public function testRejectMissingGps() {
    $g = jarvis_exif_get_gps([]);
    $this->assertNull($g);
  }
}
