import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=(p)=>fs.readFileSync(new URL(`../${p}`,import.meta.url),'utf8');
const helper=read('includes/profile-commerce-delivery-file-v1130.php');
const download=read('profile-commerce-delivery-file.php');
const workspace=read('profile-commerce-delivery.php');
const receipt=read('profile-commerce-order.php');
const privateHtaccess=read('private/.htaccess');
const gitignore=read('.gitignore');

assert.match(helper,/VP3_PROFILE_COMMERCE_DELIVERY_FILE_MAX_BYTES_V1130=52428800/,'delivery file size must be capped at 50 MB');
assert.match(helper,/private\/commerce-delivery/,'files must live in private runtime storage');
assert.match(helper,/is_uploaded_file\(/,'PHP must verify the upload originated from HTTP upload handling');
assert.match(helper,/new finfo\(FILEINFO_MIME_TYPE\)/,'file type must be detected from content');
assert.match(helper,/move_uploaded_file\(/,'accepted uploads must use PHP upload move semantics');
assert.match(helper,/bin2hex\(random_bytes\(24\)\)/,'stored file IDs must be opaque and unpredictable');
assert.match(helper,/hash_file\('sha256'/,'stored file integrity must be snapshotted');
assert.match(helper,/@chmod\(\$target,0600\)/,'stored files must use private file permissions when supported');
assert.doesNotMatch(helper,/image\/svg\+xml|text\/html|application\/x-httpd-php/,'active web content must not be allowlisted');
assert.match(helper,/\['paid','partially_refunded'\]/,'buyer download projection must disappear after a full refund');
assert.match(helper,/profile_delivery_file_saved/,'file upload must enter the canonical Commerce audit trail');
assert.match(helper,/profile_delivery_file_removed/,'file removal must enter the canonical Commerce audit trail');
assert.doesNotMatch(helper,/uploads\//,'secure delivery files must never be written into the public upload tree');

assert.match(download,/profile_commerce_customer_order_v1100\(/,'download must reuse receipt-token order authorization');
assert.match(download,/profile_commerce_delivery_file_for_customer_v1130\(/,'download must apply payment/refund delivery policy');
assert.match(download,/Content-Disposition: attachment/,'files must be served as downloads, not inline web content');
assert.match(download,/X-Content-Type-Options: nosniff/,'download must disable browser MIME sniffing');
assert.match(download,/Content-Security-Policy: default-src \\'none\\'; sandbox/,'download response must fail closed for active content');
assert.match(download,/Content-Length/,'download must bind response length to stored metadata');
assert.match(download,/filesize\(\$path\).*\$file\['bytes'\]/s,'download must reject storage-size drift');
assert.doesNotMatch(download,/X-Sendfile|X-Accel-Redirect/,'this slice must not expose private filesystem paths to a web-server redirect');

assert.match(workspace,/enctype="multipart\/form-data"/,'seller workspace must use bounded PHP file uploads');
assert.match(workspace,/name="delivery_file"/,'seller workspace must expose the secure file control');
assert.match(workspace,/profile_commerce_delivery_file_upload_v1130/,'seller upload must delegate to the secure storage helper');
assert.match(workspace,/profile_commerce_delivery_file_remove_v1130/,'seller must be able to revoke a delivered file');
assert.match(workspace,/verify_csrf\(\)/,'seller file mutations require CSRF protection');
assert.match(workspace,/Maximum 50 MB/,'seller UI must state the file cap');

assert.match(receipt,/profile_commerce_delivery_file_for_customer_v1130/,'receipt must use the customer-safe file projection');
assert.match(receipt,/profile-commerce-delivery-file\.php\?username=/,'receipt must link only through the authorized streaming endpoint');
assert.doesNotMatch(receipt,/private\/commerce-delivery/,'receipt must never reveal private storage paths');

assert.match(privateHtaccess,/Require all denied/,'private runtime storage must deny direct HTTP access');
assert.match(gitignore,/private\/\*/,'runtime private files must stay out of source control');

console.log('PROFILE_COMMERCE_SECURE_FILE_V1130_CONTRACT=PASS');
