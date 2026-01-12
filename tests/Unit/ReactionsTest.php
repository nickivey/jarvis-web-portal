<?php
use PHPUnit\Framework\TestCase;

final class ReactionsTest extends TestCase {
  public function testAddRemoveReactions(): void {
    $username = 'react_' . bin2hex(random_bytes(4));
    $email = $username . '@example.com';
    $uid = jarvis_create_user($username, $email, null, password_hash('pass', PASSWORD_DEFAULT), bin2hex(random_bytes(12)));
    $this->assertIsInt($uid);

    $channel = 'local:reacts';
    jarvis_log_local_message($uid, $channel, 'react to me', null);
    $pdo = jarvis_pdo(); $this->assertNotNull($pdo);
    $m = $pdo->query("SELECT id FROM messages WHERE user_id={$uid} AND provider='local' ORDER BY id DESC LIMIT 1")->fetch();
    $mid = (int)$m['id'];

    $ok = jarvis_add_message_reaction($uid, $mid, 'like');
    $this->assertTrue($ok);
    $ok2 = jarvis_add_message_reaction($uid, $mid, 'like'); // idempotent
    $this->assertTrue($ok2);

    $list = jarvis_list_message_reactions($mid);
    $this->assertTrue(is_array($list['summary']) && count($list['summary']) > 0);

    $rm = jarvis_remove_message_reaction($uid, $mid, 'like');
    $this->assertTrue($rm);
  }
}
