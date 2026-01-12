<?php
use PHPUnit\Framework\TestCase;

final class MessageEditTest extends TestCase {
  public function testEditLocalMessage(): void {
    $username = 'me_' . bin2hex(random_bytes(4));
    $email = $username . '@example.com';
    $uid = jarvis_create_user($username, $email, null, password_hash('pass', PASSWORD_DEFAULT), bin2hex(random_bytes(12)));
    $this->assertIsInt($uid);

    // Post a local message
    $channel = 'local:tests';
    jarvis_log_local_message($uid, $channel, 'hello #tag @' . $username, null);

    $pdo = jarvis_pdo(); $this->assertNotNull($pdo);
    $m = $pdo->query("SELECT id, message_text FROM messages WHERE user_id={$uid} AND provider='local' ORDER BY id DESC LIMIT 1")->fetch();
    $this->assertNotEmpty($m);

    // Edit it
    $ok = jarvis_edit_local_message((int)$m['id'], 'updated text #newtag', ['tags'=>['newtag']]);
    $this->assertTrue($ok);
    $m2 = $pdo->query("SELECT message_text, edited_at FROM messages WHERE id=".(int)$m['id'])->fetch();
    $this->assertEquals('updated text #newtag', $m2['message_text']);
    $this->assertNotEmpty($m2['edited_at']);
  }
}
