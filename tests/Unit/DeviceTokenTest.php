<?php
use PHPUnit\Framework\TestCase;

final class DeviceTokenTest extends TestCase {
  public function testCreateAndRevokeDeviceToken(): void {
    $username = 'testuser_' . bin2hex(random_bytes(4));
    $email = $username . '@example.com';
    // Create a user
    $uid = jarvis_create_user($username, $email, null, password_hash('pass', PASSWORD_DEFAULT), bin2hex(random_bytes(12)));
    $this->assertIsInt($uid);

    $r = jarvis_create_device_upload_token($uid, 'test token', 10);
    $this->assertArrayHasKey('token', $r);
    $this->assertNotEmpty($r['token']);

    $userRow = jarvis_get_user_for_upload_token($r['token']);
    $this->assertIsArray($userRow);
    $this->assertEquals($uid, (int)$userRow['user_id']);

    // Revoke
    $ok = jarvis_revoke_device_upload_token((int)$r['id'], $uid);
    $this->assertTrue($ok);

    $userRow2 = jarvis_get_user_for_upload_token($r['token']);
    $this->assertNull($userRow2);
  }
}
