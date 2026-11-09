# VP3 Agent Development runner

**Outline and propose** uses the model configured in VP3. **Full code & stage for launch** uses a separate development worker. The worker verifies the proposed commit, the staging ZIP, a private smoke-test receipt, and the actual preview page. The VP3 admin approves the exact staged commit and ZIP for launch review.

## Draft a development request from Chat

In private platform-admin Agent Chat, enable a development level and choose **Draft /develop** on the reply that shows the problem. The draft uses the saved request and reply for that exchange. Review the observed behavior, remove any sensitive details, and edit the expected behavior and checks in the composer. Press **Send** to create the revision. Drafting itself leaves the revision queue untouched.

## Configure VP3 on HostGator

Run **Database Upgrade**, then open **Admin → Agent Development**. Set these server environment values. A hosting account that cannot set environment variables can use the corresponding keys in its existing private `config.php` under `site`.

| Server environment | `site` key | Value |
| --- | --- | --- |
| `VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN` | `agent_development_runner_token` | Random bearer token with 43–128 URL-safe characters |
| `VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN` | `agent_development_preview_origin` | Private HTTPS preview origin on a separate host and cookie domain |
| `VP3_AGENT_DEVELOPMENT_BASE_SHA` | `agent_development_base_sha` | Optional exact 40-character deployed commit. The production ZIP supplies it through `VP3_RELEASE.json`. |

## Configure the separate worker

Use a dedicated Linux host or a dedicated Linux environment on HomeServer with Python 3.9 or later and Git. Install `workers/agent-development-runner-v1.py` from a reviewed release outside the editable checkout. Give the service account access to a local Git checkout of `davemt74-lang/software` with the deployed commit fetched and an `origin` that identifies that repository. Keep staging files and the fixture database separate from live VP3 configuration, accounts, uploads, and mail.

| Worker environment | Value |
| --- | --- |
| `VP3_AGENT_DEVELOPMENT_RUNNER_URL` | Your VP3 HTTPS URL ending in `/api/agent-development-runner-v1.php` |
| `VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN` | The same runner token configured in VP3 |
| `VP3_AGENT_DEVELOPMENT_SOURCE_REPO` | Absolute path to the local source checkout |
| `VP3_AGENT_DEVELOPMENT_ADAPTER` | Absolute path to the executable described below |
| `VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN` | The same preview origin configured in VP3 |
| `VP3_AGENT_DEVELOPMENT_PREVIEW_TOKEN` | A separate random 43–128 character URL-safe token accepted by the private preview service for smoke verification |

Start with one invocation. Schedule the same command as a worker service when ready. Each invocation processes at most one revision. Refresh the local Git checkout independently when the deployed base changes.

```sh
python3 /opt/vp3-dev/agent-development-runner-v1.py
```

The worker makes a shallow checkout of the requested base in a private temporary directory and invokes the adapter directly with JSON on standard input. The JSON contains `id`, `base_sha`, `request`, `checkout`, `artifact_path`, and `preview_origin`. The checkout is the adapter's working directory. The environment forwards basic runtime variables and `VP3_AGENT_DEVELOPMENT_ADAPTER_*` values. The runner token, the preview health token, and the lease stay with the worker.

## Example adapter for a Chat change

Install two local executables controlled by the operator. In this example `/opt/vp3-dev/code-in-sandbox` reads the job JSON, uses the coding executor and model credentials configured on the worker, edits the supplied checkout, and commits the candidate. Treat the request as data, and run any candidate-controlled commands in an isolated container or equivalent sandbox. The code executor returns only a short JSON `summary`. `/opt/vp3-dev/launch-private-stage` deploys the verified ZIP using its own stage deployment identity and runs the live smoke described below.

The example's fixed acceptance command uses the repository's Chat canvas and voice browser suite. Install its PHP and Playwright dependencies inside the fixture runner. The fixed test command should cover the requested behavior before launch review.

```python
#!/usr/bin/env python3
import json
import subprocess
import sys

job = json.load(sys.stdin)
wire = json.dumps(job).encode()
code = json.loads(subprocess.check_output(["/opt/vp3-dev/code-in-sandbox"], input=wire))
subprocess.run(["git", "diff", "--check", job["base_sha"], "HEAD"], check=True, stdout=sys.stderr)
subprocess.run(["/opt/vp3-dev/test-in-sandbox", "node", "tests/chat-canvas-voice-browser.mjs"],
               check=True, stdout=sys.stderr)
candidate = json.loads(subprocess.check_output(
    [sys.executable, "/opt/vp3-dev/agent-development-runner-v1.py", "--package"], input=wire))
stage = dict(job, **candidate)
stage.pop("request")
subprocess.run(["/opt/vp3-dev/launch-private-stage"], input=json.dumps(stage).encode(),
               check=True, stdout=sys.stderr)
preview = f"{job['preview_origin']}/revisions/{candidate['head_sha']}/"
print(json.dumps({"head_sha": candidate["head_sha"], "preview_url": preview, "summary": code["summary"],
                  "checks": ["Git diff check", "Chat canvas and voice fixture", "Private stage browser smoke"]}))
```

The `--package` helper writes the staged ZIP to the supplied `artifact_path`. It derives the included source files from the committed tree and applies the production packaging exclusions. Its generated `VP3_RELEASE.json` identifies the candidate as **VP3 Cloud Staging**. The adapter output contains the exact head, preview URL, a plain-text summary of at most 500 characters, and one to eight check names of at most 300 characters each.

### Launch and smoke-test the preview

1. Extract the candidate ZIP into an immutable private staging directory for its `head_sha`. Start the PHP app with a separate MariaDB database and fixture data. For a first setup, copy the candidate's `config-example.php`, enter only fixture database settings, and use `setup.php` to create a disposable admin. Configure the app and reverse proxy for `/revisions/<head_sha>/` and fence traffic away from live VP3 and provider services.
2. Open the staged app with a disposable fixture account and exercise the changed behavior. After the smoke succeeds, serve the exact stage receipt as `vp3-stage-receipt.json` under that candidate's preview path. Publish the receipt through the staging service after the browser test, outside model-edited source files.
3. Allow the worker's preview token to fetch that receipt and the candidate's HTML entry page over HTTPS. The entry page must respond directly with HTTP 200 and `X-VP3-Stage-Commit` set to the exact commit. Browser operators use the preview host's separate access control, such as a private VPN and staging sign-in.

```json
{
  "id": "<job id>",
  "base_sha": "<job base SHA>",
  "head_sha": "<committed candidate SHA>",
  "artifact_sha": "<SHA-256 of the staged ZIP>",
  "smoke_passed": true
}
```

The worker validates the candidate ancestry and every ZIP entry against the Git source bytes, then checks the receipt and the live HTML response on the configured origin. Redirects, stale receipts, missing checks, and mismatched builds stop the revision from reaching staged status. The temporary checkout and local ZIP are cleaned up after the run. Keep the preview and its ZIP in the private stage service for your review window.
