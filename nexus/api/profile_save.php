<?php
require __DIR__ . '/db.php';
require_post();
$me = require_staff();
$pdo = portfolio_db();
$b = body();
$v = [
    'headline'   => clean_line(str_field($b, 'headline'), 120),
    'bio'        => clean_block(str_field($b, 'bio'), 2000),
    'location'   => clean_line(str_field($b, 'location'), 100),
    'phone'      => clean_line(str_field($b, 'phone'), 30),
    'skills'     => clean_line(str_field($b, 'skills'), 500),
    'education'  => clean_block(str_field($b, 'education'), 2000),
    'experience' => clean_block(str_field($b, 'experience'), 2000),
    'linkedin'   => str_field($b, 'linkedin'),
    'github'     => str_field($b, 'github'),
    'website'    => str_field($b, 'website'),
];
foreach (['linkedin' => 'LinkedIn', 'github' => 'GitHub', 'website' => 'Website'] as $k => $label) {
    if ($v[$k] !== '' && !url_ok($v[$k])) fail("$label link must start with http:// or https://");
}
$pdo->prepare('INSERT INTO profiles (user_id, headline, bio, location, phone, skills, education, experience, linkedin, github, website)
               VALUES (:u, :headline, :bio, :location, :phone, :skills, :education, :experience, :linkedin, :github, :website)
               ON DUPLICATE KEY UPDATE headline=VALUES(headline), bio=VALUES(bio), location=VALUES(location), phone=VALUES(phone),
               skills=VALUES(skills), education=VALUES(education), experience=VALUES(experience),
               linkedin=VALUES(linkedin), github=VALUES(github), website=VALUES(website)')
    ->execute($v + ['u' => $me['id']]);
out(['ok' => true]);
