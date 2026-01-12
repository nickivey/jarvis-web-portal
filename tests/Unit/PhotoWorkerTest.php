<?php
use PHPUnit\Framework\TestCase;

final class PhotoWorkerTest extends TestCase {
  public function testEnqueueAndProcessPhotoJob(): void {
    $username = 'pw_' . bin2hex(random_bytes(4));
    $email = $username . '@example.com';
    $uid = jarvis_create_user($username, $email, null, password_hash('pass', PASSWORD_DEFAULT), bin2hex(random_bytes(12)));
    $this->assertIsInt($uid);
    $base = __DIR__ . '/../../storage/photos/' . $uid;
    if (!is_dir($base)) mkdir($base, 0770, true);
    $fname = 'test_photo_' . bin2hex(random_bytes(4)) . '.jpg';
    $fpath = $base . '/' . $fname;
    // simple JPEG header to make image functions happy
    file_put_contents($fpath, chr(0xFF) . chr(0xD8) . random_bytes(512) . chr(0xFF) . chr(0xD9));

    $pdo = jarvis_pdo();
    $pdo->prepare('INSERT INTO photos (user_id, filename, original_filename) VALUES (:u,:f,:o)')->execute([':u'=>$uid, ':f'=>$fname, ':o'=>$fname]);
    $photoId = (int)$pdo->lastInsertId();
    $this->assertGreaterThan(0, $photoId);

    $jobId = jarvis_enqueue_job('photo_reprocess', ['photo_id'=>$photoId]);
    $this->assertGreaterThan(0,$jobId);

    // Ensure job is visible to the fetcher before running worker
    $job = jarvis_fetch_next_job(['photo_reprocess']);
    $this->assertNotNull($job, 'Expected job to be present after enqueue');

    // Process our specific job deterministically
    $next = jarvis_fetch_next_job(['photo_reprocess']);
    $this->assertNotNull($next, 'expected to fetch photo_reprocess job');
    $started = jarvis_mark_job_started((int)$next['id']);
    $this->assertTrue($started, 'expected job to transition to running');
    $payload = json_decode($next['payload_json'] ?? '{}', true) ?: [];
    $this->assertArrayHasKey('photo_id', $payload);
    $res = jarvis_reprocess_photo((int)$payload['photo_id']);
    $this->assertTrue($res['ok'] ?? false, 'reprocess should succeed');
    $done = jarvis_mark_job_done((int)$next['id']);
    $this->assertTrue($done, 'expected job to be marked done');

    // verify job processed by checking no exception and OK result
    $this->assertTrue(true);
  }
}
