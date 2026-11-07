<?php
declare(strict_types=1);

$repository = getenv('VP3_PUBLICATION_REPOSITORY');
$webRoot = getenv('VP3_PUBLICATION_WEB_ROOT');
if (!$repository || !$webRoot) throw new RuntimeException('Publication browser fixture requires repository and web roots.');
define('STONEFELLOW_ROOT', $webRoot);
require $repository.'/tests/fixtures/commerce-recovery-pa2.php';
require_once $repository.'/includes/profile-agent.php';
require_once $repository.'/includes/user-agent-system-v236.php';
require_once $repository.'/includes/initiative-work-product-v1.php';

$pdo = commerce_fixture_connect_pa2(['dsn'=>'sqlite:'.$webRoot.'/publication.sqlite','user'=>null,'pass'=>null,'driver'=>'sqlite']);
if (PHP_SAPI === 'cli-server') {
    session_save_path($webRoot.'/sessions');
    session_start();
}

// The test controls sign-in and the workspace shell. Product, media, profile,
// publication and checkout reads still go through the production code.
function current_user(): ?array
{
    $id = (int)($_SERVER['HTTP_X_FIXTURE_OWNER'] ?? 0);
    return $id ? profile_user_row(db(), $id) : null;
}
function has_permission(string $permission, ?array $user=null): bool
{
    return !empty(($user ?? current_user())['is_active']) && in_array($permission, ['account.access','chat.access'], true);
}
function require_permission(string $permission): void
{
    if (!has_permission($permission)) {
        http_response_code(403);
        exit('Sign in to Profile Commerce.');
    }
}
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
}
function redirect(string $path): never
{
    header('Location: '.$path, true, 303);
    exit;
}
function csrf_token(): string
{
    return $_SESSION['fixture_csrf'] ??= bin2hex(random_bytes(24));
}
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">';
}
function verify_csrf(): bool
{
    return is_string($_POST['csrf_token'] ?? null) && hash_equals(csrf_token(), $_POST['csrf_token']);
}
if (PHP_SAPI !== 'cli') return;

if (($argv[1] ?? '') === 'init') {
    commerce_fixture_schema_pa2($pdo);
    $pdo->exec("ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(80) NOT NULL DEFAULT 'Fixture seller'");
    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NOT NULL DEFAULT 'seller@example.test'");
    $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'user'");
    $pdo->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(150) NOT NULL DEFAULT ''");
    $pdo->exec('ALTER TABLE user_agents ADD COLUMN owner_user_id INTEGER NOT NULL DEFAULT 1');
    $pdo->exec('ALTER TABLE user_agents ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');
    $pdo->exec('INSERT INTO user_agents(id,owner_user_id,is_active) VALUES(7,1,1),(8,2,1)');
    profile_agent_ensure_schema($pdo);
    $pdo->exec("INSERT INTO user_profiles(user_id,username,is_public) VALUES(1,'fixture-seller',1),(2,'fixture-other',1)");
    $pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,user_agent_id INTEGER)');
    $pdo->exec('INSERT INTO chat_conversations VALUES(11,1,7),(21,2,8)');
    $pdo->exec("CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,media_type VARCHAR(12),
        title VARCHAR(190),mime_type VARCHAR(30),file_path VARCHAR(250),created_at VARCHAR(40),updated_at VARCHAR(40),
        file_size INTEGER NOT NULL,duration_seconds INTEGER DEFAULT 0,source VARCHAR(30),metadata_json TEXT)");

    $mediaDirectory = $webRoot.'/private/user-media/1';
    mkdir($mediaDirectory, 0700, true);
    $chunk = static fn(string $type, string $data): string => pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',32,32,8,6,0,0,0))
        .$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\x24\x58\xb2\xff",32),32))).$chunk('IEND','');
    file_put_contents($mediaDirectory.'/blue-mug.png', $png);
    $pdo->prepare("INSERT INTO user_media_assets(id,user_id,media_type,title,mime_type,file_path,file_size,source,created_at,updated_at)
        VALUES(51,1,'photo','Original blue mug','image/png','private/user-media/1/blue-mug.png',?,'upload',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
        ->execute([strlen($png)]);
    $connection = commerce_fixture_insert_pa2($pdo, 'agent_commerce_provider_connections_v800', [
        'owner_user_id'=>1,'provider'=>'stripe','external_account_id'=>'acct_fixture_listing','account_label'=>'Fixture Stripe',
        'status'=>'connected','access_token_ciphertext'=>agent_commerce_encrypt_v800('fixture-access-token'),
    ]);
    $scope = ['agent_id'=>7,'conversation_id'=>11];
    $input = ['source_id'=>'51','request_id'=>hash('sha256','browser product listing draft')];
    $preview = initiative_work_product_preview_v1($pdo,1,$scope,$input);
    $draft = initiative_work_product_create_v1($pdo,1,$scope,$input,$preview,static fn(): string =>
        '{"title":"Handmade blue mug","description":"A ceramic mug with a satin blue glaze."}');
    echo json_encode(['product_id'=>$draft['product_id'],'connection_id'=>$connection,'source_hash'=>hash('sha256',$png)], JSON_THROW_ON_ERROR);
    return;
}
if (($argv[1] ?? '') === 'state') {
    $products = $pdo->query('SELECT id,title,description,price_cents,currency,payment_mode,product_type,fulfillment_type,provider_mode,
        fixed_connection_id,is_active,metadata_json FROM agent_commerce_products_v800')->fetchAll();
    echo json_encode([
        'products'=>$products,
        'orders'=>(int)$pdo->query('SELECT COUNT(*) FROM agent_commerce_orders_v800')->fetchColumn(),
        'promotions'=>(int)$pdo->query('SELECT COUNT(*) FROM agent_commerce_promotions_v1')->fetchColumn(),
    ],JSON_THROW_ON_ERROR);
    return;
}
throw new RuntimeException('Unknown publication fixture command.');
