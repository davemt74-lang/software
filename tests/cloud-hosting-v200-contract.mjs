import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const domains=read('includes/cloud-hosting-domains-v200.php');
const ui=read('includes/cloud-hosting-ui-v140.php');
const agent=read('includes/cloud-hosting-agent-v130.php');
const page=read('hosting.php');
const css=read('cloud-hosting-v140.css');
const subs=read('includes/subscription-schema.php');
const v100=read('includes/cloud-hosting-v100.php');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const workflow=read('.github/workflows/cloud-hosting-v100.yml');

assert.match(domains,/VP3_CLOUD_HOSTING_DOMAINS_V200/);
assert.match(domains,/CREATE TABLE IF NOT EXISTS cloud_hosting_custom_domains/);
assert.match(domains,/UNIQUE KEY uq_cloud_hosting_custom_domain_hostname/);
assert.match(domains,/verification_token_hash CHAR\(64\)/);
assert.match(domains,/verification_token_enc LONGTEXT/);
assert.match(domains,/ai_encrypt_secret\(\$token\)/);
assert.match(domains,/ai_decrypt_secret\(\(string\)\$row\['verification_token_enc'\]\)/);
assert.match(domains,/vp3_cloud_hosting_domains_v200_verify_ownership/);
assert.match(domains,/DNS_TXT/);
assert.match(domains,/vp3-verification=/);
assert.match(domains,/vp3_cloud_hosting_domains_v200_verify_routing/);
assert.match(domains,/DNS_CNAME\|DNS_A\|DNS_AAAA/);
assert.match(domains,/array_intersect/);
assert.match(domains,/vp3_cloud_hosting_domains_v200_record_tls/);
assert.match(domains,/vp3_cloud_hosting_domains_v200_set_canonical/);
assert.match(domains,/vp3_cloud_hosting_domains_v200_set_redirect/);
assert.match(domains,/vp3_cloud_hosting_domains_v200_detach/);
assert.match(domains,/vp3_cloud_hosting_domains_v200_migrate/);
assert.match(domains,/vp3_cloud_hosting_domains_v200_edge_projection/);
assert.match(domains,/upstream_hostname/);
assert.match(domains,/redirect_to/);
assert.match(domains,/cloud_edge_rewrites_upstream_host'=>true/);
assert.match(domains,/home_server_revision_unchanged_by_aliases'=>true/);
assert.match(domains,/active_tls_requires_future_expiry'=>true/);
assert.match(domains,/certificate is expired/);
assert.match(domains,/home_server_alias_engine'=>false/);
assert.match(domains,/home_server_revision_unchanged_by_aliases'=>true/);
assert.doesNotMatch(domains,/UPDATE cloud_hosting_sites SET desired_revision=desired_revision\+1/);
assert.doesNotMatch(domains,/verification_token_publicly_exposed'=>true/);

assert.match(subs,/'hosting\.custom_domains'/);
assert.match(v100,/'hosting\.custom_domains'/);
assert.match(ui,/'custom_domains'/);
assert.match(ui,/domain\.attach/);
assert.match(ui,/domain\.verify_ownership/);
assert.match(ui,/domain\.verify_routing/);
assert.match(ui,/domain\.canonical/);
assert.match(ui,/domain\.redirect/);
assert.match(ui,/domain\.detach/);
assert.match(ui,/domain\.migrate/);
assert.match(ui,/custom_domain_management'=>true/);

assert.match(agent,/'domain\.attach'/);
assert.match(agent,/'domain\.canonical'/);
assert.match(agent,/'domain\.detach'/);
assert.match(agent,/verify custom-domain ownership\/routing read-only/);
assert.match(agent,/vp3_cloud_hosting_domains_v200_attach/);
assert.match(agent,/dns_instructions_available_in_hosting_ui/);
assert.match(agent,/vp3_cloud_hosting_domains_v200_set_canonical/);
assert.match(agent,/vp3_cloud_hosting_domains_v200_detach/);

assert.match(page,/Custom Domains/);
assert.match(page,/name="hostname"/);
assert.match(page,/domain\.verify_ownership/);
assert.match(page,/domain\.verify_routing/);
assert.match(page,/domain\.canonical/);
assert.match(page,/domain\.migrate/);
assert.match(page,/domain\.detach/);
assert.match(page,/Ownership TXT/);
assert.match(page,/Routing/);
assert.match(css,/hosting-domain-card/);
assert.match(css,/hosting-dns-instructions/);

assert.match(bootstrap,/cloud-hosting-domains-v200\.php/);
assert.match(setup,/vp3_cloud_hosting_domains_v200_ensure_schema\(\$pdo\)/);
assert.match(upgrade,/vp3_cloud_hosting_domains_v200_schema_ready\(\)/);
assert.match(upgrade,/vp3_cloud_hosting_domains_v200_ensure_schema\(\$pdo\)/);

assert.match(workflow,/cloud-hosting-domains-v200\.php/);
assert.match(workflow,/cloud-hosting-v200-contract\.mjs/);
assert.match(workflow,/cloud-hosting-v200-mysql\.php/);

console.log('Cloud Hosting V2 Section 1 custom-domain contract: PASS');
