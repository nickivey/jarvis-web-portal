<?php
use PHPUnit\Framework\TestCase;

final class MessageThreadTest extends TestCase {
  public function testThreadReplyAndList(): void {
    $username = 'thread_' . bin2hex(random_bytes(4));
    $email = $username . '@example.com';
    $uid = jarvis_create_user($username, $email, null, password_hash('pass', PASSWORD_DEFAULT), bin2hex(random_bytes(12)));
    $this->assertIsInt($uid);

    $channel = 'local:threads';
    jarvis_log_local_message($uid, $channel, 'parent message', null);
    $pdo = jarvis_pdo(); $this->assertNotNull($pdo);
    $parent = $pdo->query("SELECT id FROM messages WHERE user_id={$uid} AND provider='local' ORDER BY id DESC LIMIT 1")->fetch();
    $this->assertNotEmpty($parent);
    $pid = (int)$parent['id'];

    // Reply
    $rid = jarvis_log_local_reply($uid, $channel, $pid, 'child reply', null);
    $this->assertGreaterThan(0, $rid);

    // List thread
    $rows = jarvis_list_thread_messages($pid, 10, 0);
    $this->assertNotEmpty($rows);
    $this->assertEquals('child reply', $rows[0]['message_text']);
  }
}
