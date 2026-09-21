<?php
declare(strict_types=1);

function vp3_marketing_public_path(string $path): string
{
    return match ($path) {
        '/profile-agent.php' => '/profile-agent-overview.php',
        '/calendar.php' => '/calendar-service.php',
        '/local-knowledge.php' => '/local-knowledge-overview.php',
        '/team.php' => '/about-team.php',
        default => $path,
    };
}

function vp3_marketing_page_data(string $slug): ?array
{
    $base = [
        'product' => [
            'title' => 'Product — VP3', 'active' => 'product', 'kicker' => 'VP3 Product',
            'headline' => 'One AI identity. One assistant. One place to work from.',
            'intro' => 'VP3 brings your assistant, public presence, private context, workflows, scheduling, commerce, and optional self-hosted capabilities into one connected product.',
            'why' => 'VP3 is designed around one identity and one Agent system, so features can share approved context without turning into separate AI silos.',
            'cards' => [
                ['AI Assistant','A persistent assistant for conversations, knowledge, tasks, workflows, scheduling, commerce, and follow-through.','/ai-assistant.php'],
                ['Personal URL','A shareable public destination for your identity, Agent, booking, products, and contact paths.','/personal-url.php'],
                ['Profile Agent','A public-facing Agent that can represent you within explicit permissions and data boundaries.','/profile-agent.php'],
                ['HomeServer','Pair VP3 Cloud with private local knowledge, tools, models, skills, and compute.','/homeserver.php'],
            ],
        ],
        'ai-assistant' => [
            'title' => 'AI Assistant — VP3', 'active' => 'product', 'kicker' => 'AI Assistant',
            'headline' => 'An assistant that can keep the thread and move the work forward.',
            'intro' => 'The VP3 AI Assistant is the primary conversation and action layer across your private workspace, profile, teams, scheduling, commerce, knowledge, and approved tools.',
            'why' => 'The value is not a single chat window. It is an Agent that can use the right approved context and then hand work to durable application infrastructure when action is required.',
            'cards' => [
                ['Conversation','Use one main Agent surface for questions, planning, follow-up, and ongoing work.','/product.php'],
                ['Knowledge + memory','Ground work in context you have saved, connected, or authorized.','/local-knowledge.php'],
                ['Durable workflows','Route approved Agent work through durable jobs instead of fragile one-off requests.','/services.php'],
                ['Tools + skills','Use named, authorized capabilities instead of unrestricted machine access.','/tools-skills.php'],
            ],
        ],
        'profile-agent' => [
            'title' => 'Profile Agent — VP3', 'active' => 'product', 'kicker' => 'Profile Agent',
            'headline' => 'Put an Agent on your profile without giving away your private workspace.',
            'intro' => 'Profile Agent is the public-facing layer of VP3: a controlled Agent that can answer, guide, qualify, schedule, and support visitors using only public or explicitly permitted context.',
            'why' => 'Your public Agent should be useful without silently inheriting private Knowledge, internal tools, HomeServer paths, administrative permissions, or account-only data.',
            'cards' => [
                ['Public profile context','Use your published profile, products, booking options, and public information.','/personal-url.php'],
                ['Booking','Guide visitors into free or paid appointment flows when scheduling is relevant.','/booking.php'],
                ['Commerce','Surface public products without exposing seller-only financial data.','/ecommerce.php'],
                ['Boundaries','Keep public representation separate from private Agent authority.','/mission.php'],
            ],
        ],
        'personal-url' => [
            'title' => 'Personal URL — VP3', 'active' => 'product', 'kicker' => 'Personal URL',
            'headline' => 'A personal destination for your identity, Agent, booking, and commerce.',
            'intro' => 'Your VP3 personal URL can become the public front door to your profile, Agent, availability, products, public work, and contact options.',
            'why' => 'One useful public destination can replace a collection of disconnected profile, booking, contact, and storefront links while keeping private account context private.',
            'cards' => [
                ['Profile','Present the public information you want people to see in one place.','/profile-agent.php'],
                ['Agent','Let visitors interact with your Profile Agent when conversation is more useful than static navigation.','/profile-agent.php'],
                ['Booking','Offer free or paid scheduling from the same public destination.','/booking.php'],
                ['Products','Publish products or services and connect them to VP3 commerce.','/ecommerce.php'],
            ],
        ],
        'services' => [
            'title' => 'Services — VP3', 'active' => 'services', 'kicker' => 'VP3 Services',
            'headline' => 'Capture it. Understand it. Coordinate it. Sell it.',
            'intro' => 'VP3 services share context with the same account and Agent instead of behaving like unrelated utilities.',
            'why' => 'A transcript can become Knowledge, a booking can trigger preparation, and a purchase can become a customer lifecycle event without creating a separate reasoning system for each service.',
            'cards' => [
                ['Transcription','Capture meetings, calls, interviews, voice notes, and other recorded conversations.','/transcriptions.php'],
                ['AI Summary','Turn long conversations into decisions, action items, questions, and reusable knowledge.','/ai-summary.php'],
                ['Calendar + Booking','Connect availability, calendar context, free or paid scheduling, reminders, and lifecycle.','/booking.php'],
                ['Ecommerce','Publish products, accept orders, manage delivery, refunds, and seller activity.','/ecommerce.php'],
            ],
        ],
        'annotations' => [
            'title' => 'Annotations — VP3', 'active' => 'services', 'kicker' => 'Annotations',
            'headline' => 'Capture what matters on the web and keep the source attached.',
            'intro' => 'VP3 Annotations let you capture highlights, screenshots, notes, and source context from the browser, then organize, discuss, save, and reuse that material across research and Agent workflows.',
            'why' => 'Useful research needs more than a copied quote. VP3 keeps the annotation connected to its source and captured context so people and Agents can review what was saved, discuss it, add it to research, and return to the original material.',
            'cards' => [
                ['Capture source context','Save selected text, screenshots, notes, and source details while you work in the browser.','/chrome-extension.php'],
                ['Research workflow','Save annotations, add them to research, and keep useful source material connected to later work.','/services.php'],
                ['Discussion + sharing','Publish or share approved annotations and keep comments and collaboration attached to the item.','/teams.php'],
                ['Agent follow-through','Bring captured source material into VP3 so the Agent can use approved context for summaries, questions, and next actions.','/ai-assistant.php'],
            ],
        ],
        'agent-analytics' => [
            'title' => 'Agent Analytics — VP3', 'active' => 'services', 'kicker' => 'Agent Analytics',
            'headline' => 'See what your Profile Agent and public offers are turning into.',
            'intro' => 'Agent Analytics connects profile visits, booking and product intent, verified conversions, attributed revenue, traffic sources, and opportunity signals in one owner-facing view.',
            'why' => 'Analytics are more useful when they connect attention to outcomes. VP3 can compare visits, intent, conversions, booking and product performance, source attribution, and revenue without turning visitor identity into the reporting product.',
            'cards' => [
                ['Profile activity','See profile visits and public conversion activity across the same Profile Agent experience.','/profile-agent-overview.php'],
                ['Booking + product intent','Understand which booking offers and products are attracting measurable intent.','/booking.php'],
                ['Conversions + revenue','Connect confirmed booking and product outcomes to conversion rates and per-currency revenue reporting.','/ecommerce.php'],
                ['Sources + opportunities','Review attributed traffic sources, period comparisons, top performers, and Agent-identified conversion opportunities.','/ai-assistant.php'],
            ],
        ],

        'ai-summary' => [
            'title' => 'AI Summary — VP3', 'active' => 'services', 'kicker' => 'AI Summary',
            'headline' => 'Get the useful parts without rereading the whole conversation.',
            'intro' => 'AI Summary turns recorded or written conversations into structured outputs that can be reviewed, saved, shared, and reused as context for what comes next.',
            'why' => 'A useful summary should reduce the time between a long conversation and the next decision, task, meeting, or piece of reusable knowledge while preserving the source for verification.',
            'cards' => [
                ['Decisions','Pull out conclusions, commitments, and unresolved choices.','/transcriptions.php'],
                ['Action items','Identify follow-up work and make it easier to move into a workflow.','/services.php'],
                ['Key questions','Surface uncertainty and places where more context is needed.','/ai-assistant.php'],
                ['Knowledge','Save approved outcomes into the appropriate knowledge scope.','/local-knowledge.php'],
            ],
        ],
        'calendar' => [
            'title' => 'Calendar — VP3', 'active' => 'services', 'kicker' => 'Calendar',
            'headline' => 'Give your Agent scheduling context without giving up calendar control.',
            'intro' => 'VP3 Calendar connects availability, scheduling rules, sync, appointment context, and Agent coordination so time becomes part of the same workflow system.',
            'why' => 'Availability, conflict checks, booking lifecycle, calendar sync, preparation, and follow-up should cooperate around one canonical scheduling model.',
            'cards' => [
                ['Availability','Define when you can be booked and let scheduling respect those rules.','/booking.php'],
                ['Calendar sync','Keep VP3 scheduling aligned with supported external calendar activity.','/booking.php'],
                ['Agent context','Use relevant upcoming meeting context within permission boundaries.','/ai-assistant.php'],
                ['Team scheduling','Support host assignment and team-aware scheduling.','/teams.php'],
            ],
        ],
        'booking' => [
            'title' => 'Booking — VP3', 'active' => 'services', 'kicker' => 'Booking',
            'headline' => 'Free or paid booking, connected to the full appointment lifecycle.',
            'intro' => 'VP3 Booking connects public scheduling with canonical appointment state, calendar state, payment state, reminders, intake, and Agent follow-up.',
            'why' => 'Paid and unpaid appointments use the same scheduling foundation, so rescheduling, reminders, completion, cancellation, and no-show handling do not fragment into separate systems.',
            'cards' => [
                ['Free booking','Offer appointment types that do not require payment.','/calendar.php'],
                ['Paid booking','Require payment where the service calls for it.','/ecommerce.php'],
                ['Lifecycle','Support confirmation, rescheduling, completion, cancellation, and no-show states.','/calendar.php'],
                ['Agent follow-up','Use relevant appointment context for preparation and permitted follow-up.','/ai-assistant.php'],
            ],
        ],
        'ecommerce' => [
            'title' => 'Ecommerce — VP3', 'active' => 'services', 'kicker' => 'Ecommerce',
            'headline' => 'Sell from the same profile where people already discover and interact with you.',
            'intro' => 'VP3 Commerce connects public products, checkout, order lifecycle, delivery, refunds, seller alerts, and Agent context to the same account and public profile.',
            'why' => 'Public product discovery can stay connected to the relationship while canonical order, payment, refund, delivery, and seller-only records remain authoritative.',
            'cards' => [
                ['Public products','Publish products or services from the profile experience.','/personal-url.php'],
                ['Checkout + orders','Move customers from discovery into a canonical order lifecycle.','/profile-agent.php'],
                ['Delivery + fulfillment','Track order progress without losing customer context.','/services.php'],
                ['Refunds + seller alerts','Keep exceptions visible to sellers and approved Agent workflows.','/ai-assistant.php'],
            ],
        ],
        'homeserver' => [
            'title' => 'HomeServer — VP3', 'active' => 'homeserver', 'kicker' => 'VP3 HomeServer',
            'headline' => 'Use the Cloud, self-host private capabilities, or connect both.',
            'intro' => 'HomeServer is the private capability layer for VP3 and other authorized front ends, keeping local knowledge, tools, skills, models, and compute under hardware you control.',
            'why' => 'HomeServer is a capability platform, not just storage. Pairing creates an explicit trust boundary while local files, paths, indexes, tools, and private execution remain locally authoritative.',
            'cards' => [
                ['Cloud vs. self-hosted','Choose Cloud convenience, local control, or a paired hybrid architecture.','/cloud-vs-self-hosted.php'],
                ['Paired devices','Authorize supported front ends to use selected HomeServer capabilities.','/paired-devices.php'],
                ['Models','Use OpenRouter, model choice, and local/private execution options.','/model-choice.php'],
                ['Local capabilities','Keep Knowledge, tools, skills, and private operations under local authority.','/local-knowledge.php'],
            ],
        ],
        'cloud-vs-self-hosted' => [
            'title' => 'Cloud vs. Self-hosted — VP3', 'active' => 'homeserver', 'kicker' => 'Deployment',
            'headline' => 'Cloud convenience and self-hosted control do not have to be mutually exclusive.',
            'intro' => 'Use VP3 Cloud for broadly connected account experiences and pair HomeServer when knowledge, tools, models, or execution should remain on hardware you control.',
            'why' => 'Choose by capability: some features benefit from Cloud reach, while others benefit from local privacy, control, or compute. Hybrid pairing lets both sides keep clear authority.',
            'cards' => [
                ['VP3 Cloud','Public profile, account experience, connected services, and internet-facing coordination.','/product.php'],
                ['Self-hosted HomeServer','Private local knowledge, capabilities, tools, skills, models, and execution.','/homeserver.php'],
                ['Hybrid pairing','Let Cloud and HomeServer cooperate through explicit pairing.','/paired-devices.php'],
                ['Authority separation','Keep native local details locally authoritative.','/local-knowledge.php'],
            ],
        ],
        'paired-devices' => [
            'title' => 'Paired Devices — VP3', 'active' => 'homeserver', 'kicker' => 'Pairing',
            'headline' => 'Connect authorized front ends without turning HomeServer into a public endpoint.',
            'intro' => 'Pairing lets supported VP3 experiences request approved HomeServer capabilities through an explicit trust relationship.',
            'why' => 'Connection and permission are separate decisions. A paired front end should still receive only the capabilities it is allowed to use, without unnecessary local implementation details.',
            'cards' => [
                ['Explicit pairing','Establish trust before private capabilities are available.','/homeserver.php'],
                ['Capability discovery','Learn what HomeServer is prepared to provide without blanket access.','/tools-skills.php'],
                ['Permission upgrades','Require deliberate approval for sensitive new capabilities.','/local-knowledge.php'],
                ['Multiple front ends','Use HomeServer as a private capability platform for authorized wrappers.','/cloud-vs-self-hosted.php'],
            ],
        ],
        'openrouter' => [
            'title' => 'OpenRouter — VP3', 'active' => 'homeserver', 'kicker' => 'OpenRouter',
            'headline' => 'Model choice without hard-wiring the whole product to one provider.',
            'intro' => 'VP3 can use OpenRouter as part of its model-routing strategy while HomeServer remains available for local or private execution.',
            'why' => 'Provider choice should remain a runtime concern. Identity, permissions, tools, jobs, events, and account controls should continue to work even when the underlying model changes.',
            'cards' => [
                ['Provider choice','Use a routing layer that can expose compatible models from more than one provider.','/model-choice.php'],
                ['Model choice','Select models based on the job rather than treating every task as identical.','/model-choice.php'],
                ['Usage accounting','Keep AI capacity inside VP3 package and token accounting where applicable.','/token-packages.php'],
                ['Hybrid execution','Use provider-hosted models alongside HomeServer capabilities.','/homeserver.php'],
            ],
        ],
        'model-choice' => [
            'title' => 'Model Choice — VP3', 'active' => 'homeserver', 'kicker' => 'Model Choice',
            'headline' => 'Use the model that fits the work instead of forcing every task through one default.',
            'intro' => 'Route work intentionally across supported Cloud and local models based on capability, privacy, availability, latency, and cost.',
            'why' => 'The Agent identity and trust model should outlive any one model provider. Changing models should not silently grant new data, tools, or execution authority.',
            'cards' => [
                ['Capability','Use stronger reasoning where the task justifies it.','/ai-assistant.php'],
                ['Privacy','Prefer local or private execution when the data boundary calls for it.','/homeserver.php'],
                ['Availability','Keep alternatives available when one provider is unavailable.','/openrouter.php'],
                ['Cost','Balance model capability against token and provider cost.','/token-packages.php'],
            ],
        ],
        'local-knowledge' => [
            'title' => 'Local Knowledge — VP3', 'active' => 'homeserver', 'kicker' => 'Local Knowledge',
            'headline' => 'Let your Agent use local knowledge without uploading your whole filesystem.',
            'intro' => 'HomeServer can map and manage private collections while VP3 receives only the approved context and capability required for the task.',
            'why' => 'The private machine remains authoritative for native files, mapped folders, indexes, and local paths. Cloud workflows do not need those paths to use approved Knowledge capability.',
            'cards' => [
                ['Local authority','Keep native files, mappings, indexes, and collections authoritative on HomeServer.','/homeserver.php'],
                ['No path leakage','Avoid storing private filesystem paths in VP3 Cloud.','/cloud-vs-self-hosted.php'],
                ['Scoped access','Separate knowledge use by collection, account, workspace, or permission scope.','/tools-skills.php'],
                ['Agent context','Use approved local Knowledge without making it public profile data.','/ai-assistant.php'],
            ],
        ],
        'tools-skills' => [
            'title' => 'Tools + Skills — VP3', 'active' => 'homeserver', 'kicker' => 'Tools + Skills',
            'headline' => 'Give the Agent capabilities deliberately, one boundary at a time.',
            'intro' => 'HomeServer tools and skills let authorized Agents do useful local work while capability discovery, permission checks, and durable execution keep the trust boundary explicit.',
            'why' => 'A tool should not equal blanket machine access. Capabilities should be named, bounded, permissioned, and observable, with execution separated from reasoning.',
            'cards' => [
                ['Capability registry','Advertise supported tools and skills through a defined contract.','/paired-devices.php'],
                ['Permission checks','Require the right account or pairing authority before use.','/homeserver.php'],
                ['Durable jobs','Move long-running work into the existing job and worker runtime.','/ai-assistant.php'],
                ['Local execution','Keep private operations on HomeServer when they do not belong in the Cloud.','/cloud-vs-self-hosted.php'],
            ],
        ],
        'pricing-monthly' => [
            'title' => 'Monthly Pricing — VP3', 'active' => 'pricing', 'kicker' => 'Monthly',
            'headline' => 'A simple recurring cadence for regular VP3 use.',
            'intro' => 'Monthly access fits individuals and teams using VP3 continuously across conversations, knowledge, scheduling, collaboration, and Agent workflows.',
            'why' => 'Actual package names, enabled features, limits, and current prices remain on the live Pricing page so this explainer never becomes a stale source of truth.',
            'cards' => [
                ['Recurring access','Use a predictable monthly cadence for ongoing work.','/pricing.php'],
                ['Feature entitlements','Control commercial capabilities without changing security permissions.','/pricing.php'],
                ['AI allowance','Use included AI capacity for the package period.','/token-packages.php'],
                ['Change later','Evolve package assignment as usage changes.','/contact.php'],
            ],
        ],
        'pricing-weekly' => [
            'title' => 'Weekly Access — VP3', 'active' => 'pricing', 'kicker' => 'Weekly',
            'headline' => 'Short-cycle access for projects, launches, and changing workloads.',
            'intro' => 'Weekly access is the short-cycle pricing concept for intensive or limited-duration VP3 use.',
            'why' => 'Weekly availability depends on the live package catalog. If no weekly package is currently published, contact VP3 for current short-cycle options rather than relying on a hard-coded offer.',
            'cards' => [
                ['Project-based use','Fit transcription, Agent, scheduling, or collaboration spikes.','/services.php'],
                ['Short commitment','Use a smaller commercial window for temporary needs.','/contact.php'],
                ['Token flexibility','Add AI capacity separately where available.','/token-packages.php'],
                ['Upgrade path','Move into monthly or yearly access as usage becomes ongoing.','/pricing.php'],
            ],
        ],
        'pricing-yearly' => [
            'title' => 'Yearly Pricing — VP3', 'active' => 'pricing', 'kicker' => 'Yearly',
            'headline' => 'Longer-term access for people building VP3 into their workflow.',
            'intro' => 'Yearly access is designed for individuals and teams that expect VP3 to remain a continuing part of their operating system.',
            'why' => 'When annual pricing is configured for a package, the canonical Pricing page can display it alongside monthly pricing while identity and security authority remain independent of billing cadence.',
            'cards' => [
                ['Continuity','Keep the same Agent identity, knowledge, profile, and workflow context.','/product.php'],
                ['Team planning','Use a longer cadence for shared workspaces and scheduling.','/teams.php'],
                ['Live catalog','Read current annual values from the configured package catalog.','/pricing.php'],
                ['AI capacity','Use included allowances and add token capacity when needed.','/token-packages.php'],
            ],
        ],
        'token-packages' => [
            'title' => 'AI Token Packages — VP3', 'active' => 'pricing', 'kicker' => 'Token Packages',
            'headline' => 'Add AI capacity without changing what the Agent is allowed to access.',
            'intro' => 'Token packages extend AI usage capacity separately from identity and security permissions.',
            'why' => 'More AI capacity should never grant private data access, administrative controls, tools, or workflow authority the account does not already have.',
            'cards' => [
                ['Included allowance','Packages can include AI token capacity for an active period.','/pricing.php'],
                ['Added credits','Additional token credits can extend included capacity where configured.','/pricing.php'],
                ['Usage accounting','Track provider-reported input and output token usage.','/openrouter.php'],
                ['Permission separation','Increase capacity without increasing authority.','/ai-assistant.php'],
            ],
        ],
        'team' => [
            'title' => 'Team — VP3', 'active' => 'about', 'kicker' => 'Team',
            'headline' => 'A product effort focused on giving people more control over their AI.',
            'intro' => 'VP3 is being built around a simple idea: an Agent should become more useful as it gains approved context and capabilities without requiring the user to surrender control.',
            'why' => 'This page intentionally avoids invented staff biographies. Real names, roles, bios, and links can be added as the public team grows.',
            'cards' => [
                ['Product-led','Build connected real workflows instead of disconnected AI demos.','/mission.php'],
                ['Privacy-aware','Treat Cloud convenience and self-hosted capability as complementary.','/homeserver.php'],
                ['Workflow-driven','Connect scheduling, commerce, knowledge, transcription, teams, and Agent actions.','/case-studies.php'],
                ['Reachable','Use the Contact page for product, partnership, and demo conversations.','/contact.php'],
            ],
        ],
        'mission' => [
            'title' => 'Mission — VP3', 'active' => 'about', 'kicker' => 'Mission',
            'headline' => 'Make AI more useful without making the user less in control.',
            'intro' => 'VP3 is built around user-controlled identity, context, permissions, public representation, and optional self-hosted capability.',
            'why' => 'More context and more tools can make an Agent useful, but each capability should come from an intentional permission, pairing, account relationship, or public publishing decision.',
            'cards' => [
                ['User-controlled context','Use what the user chooses to connect, not unlimited assumed access.','/ai-assistant.php'],
                ['Private capability','Keep local Knowledge, tools, and compute under HomeServer control.','/homeserver.php'],
                ['Public representation','Let Profile Agent help visitors without exposing the private workspace.','/profile-agent.php'],
                ['Durable action','Route approved Agent work through observable infrastructure.','/services.php'],
            ],
        ],
        'case-studies' => [
            'title' => 'Case Studies — VP3', 'active' => 'about', 'kicker' => 'Case Studies',
            'headline' => 'See how the pieces of VP3 can work together in real workflows.',
            'intro' => 'These are product workflow studies, not fabricated customer claims. They show how current VP3 capabilities can be combined across a lifecycle.',
            'why' => 'The useful question is not whether one isolated feature works. It is whether profile, Agent, scheduling, commerce, knowledge, teams, and HomeServer can cooperate without losing their own canonical records.',
            'cards' => [
                ['Professional booking','Profile visitor → Profile Agent → availability → booking → preparation → follow-up.','/booking.php'],
                ['Conversation to knowledge','Recording → transcription → AI summary → scoped Knowledge → later Agent context.','/ai-summary.php'],
                ['Profile commerce','Profile → product discovery → checkout → order lifecycle → seller alert → support.','/ecommerce.php'],
                ['Private hybrid AI','VP3 Cloud → paired HomeServer → local Knowledge/tool → durable Agent job.','/homeserver.php'],
            ],
        ],
        'testimonials' => [
            'title' => 'Testimonials — VP3', 'active' => 'about', 'kicker' => 'Testimonials',
            'headline' => 'Real feedback belongs here. Invented praise does not.',
            'intro' => 'VP3 will publish customer and partner quotes only when they are real, attributable or permissioned, and appropriate to share publicly.',
            'why' => 'This page deliberately avoids fictional names, composite companies, star ratings, and made-up success metrics. Real feedback should identify the workflow and the specific value the user experienced.',
            'cards' => [
                ['Usefulness','Did VP3 reduce steps, save time, or make context easier to use?','/product.php'],
                ['Clarity','Did the Agent make the next action easier to understand?','/ai-assistant.php'],
                ['Control','Did privacy, permissions, and HomeServer boundaries match expectations?','/mission.php'],
                ['Reliability','Did scheduling, commerce, workflows, and Agent jobs hold up end to end?','/services.php'],
            ],
        ],
        'social' => [
            'title' => 'Social Links — VP3', 'active' => 'about', 'kicker' => 'Social',
            'headline' => 'Follow VP3 from links we can verify and maintain.',
            'intro' => 'This page is the canonical place for VP3 public channels and is ready for verified links as official accounts are published.',
            'why' => 'Rather than guessing usernames or linking unofficial accounts, VP3 can add verified destinations here and keep one stable directory for the rest of the site.',
            'cards' => [
                ['Official website','Use the VP3 public site as the canonical product source.','/index.php'],
                ['Product updates','Follow public product and launch information as verified channels are added.','/about.php'],
                ['Founder + team','Add confirmed team profiles as public destinations are verified.','/team.php'],
                ['Direct contact','Use the Contact page for current demos, partnerships, support, or questions.','/contact.php'],
            ],
        ],
    ];

    return $base[$slug] ?? null;
}

function vp3_render_marketing_page(string $slug): void
{
    $page = vp3_marketing_page_data($slug);
    if ($page === null) {
        http_response_code(404);
        vp3_public_header('Page not found — VP3', 'The requested VP3 page could not be found.');
        echo '<main><section class="vp3-section"><div class="vp3-wrap"><div class="vp3-cta-box"><div><h2>Page not found.</h2><p>Return to the VP3 homepage.</p></div><a class="vp3-btn primary" href="'.e(url('/index.php')).'">VP3 Home →</a></div></div></section></main>';
        vp3_public_footer();
        return;
    }

    $meta = 'VP3 — ' . (string)$page['intro'];
    vp3_public_header((string)$page['title'], $meta, ['active' => (string)$page['active'], 'body_class' => 'vp3-marketing-page']);
    ?>
<section class="vp3-public-hero vp3-marketing-hero">
  <div class="vp3-kicker"><?= e((string)$page['kicker']) ?></div>
  <h1><?= e((string)$page['headline']) ?></h1>
  <p><?= e((string)$page['intro']) ?></p>
  <div class="vp3-marketing-hero-actions">
    <a class="vp3-btn primary" href="<?= e(url('/signup.php')) ?>">Get VP3 →</a>
    <a class="vp3-btn" href="<?= e(url('/book-demo.php')) ?>">Book a demo</a>
  </div>
</section>
<main>
  <section class="vp3-section"><div class="vp3-wrap">
    <div class="vp3-marketing-feature-grid">
      <?php foreach ($page['cards'] as $card): ?>
      <a class="vp3-marketing-feature" href="<?= e(url(vp3_marketing_public_path((string)$card[2]))) ?>">
        <span class="vp3-marketing-arrow" aria-hidden="true">↗</span>
        <h2><?= e((string)$card[0]) ?></h2>
        <p><?= e((string)$card[1]) ?></p>
        <span class="vp3-marketing-link">Learn more →</span>
      </a>
      <?php endforeach; ?>
    </div>
  </div></section>

  <section class="vp3-section soft"><div class="vp3-wrap vp3-marketing-why">
    <div><div class="vp3-kicker">Why it matters</div><h2>Designed to work as part of the same VP3 system.</h2></div>
    <p><?= e((string)$page['why']) ?></p>
  </div></section>

  <section class="vp3-section"><div class="vp3-wrap"><div class="vp3-cta-box vp3-marketing-cta">
    <div><h2>Build this into your VP3.</h2><p>Start with the public Cloud experience and add the capabilities that fit how you work.</p></div>
    <div class="vp3-marketing-cta-actions"><a class="vp3-btn primary" href="<?= e(url('/signup.php')) ?>">Get VP3 →</a><a class="vp3-btn" href="<?= e(url('/contact.php')) ?>">Contact us</a></div>
  </div></div></section>
</main>
<?php
    vp3_public_footer();
}
