import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const { chromium } = createRequire(import.meta.url)(process.env.VP3_PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({
  headless: true,
  args: ['--no-sandbox'],
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined
});

const base = 'https://creative.test';
const asset = id => '/media-library-file-v86.php?id=' + id;
const original = asset(1);
const generated = asset(4);
const settings = {originalUrl: original, generatedUrl: generated, headline: 'A blue mug for slow mornings', copy: 'See the glazed finish in your favorite light.'};

try {
  const page = await browser.newPage({viewport: {width: 412, height: 915}});
  await page.route(base + '/', route => route.fulfill({contentType: 'text/html', body: '<!doctype html><title>Private promotion fixture</title>'}));
  await page.goto(base);
  const source = await page.evaluate(() => {
    function png(rectangles) {
      const canvas = Object.assign(document.createElement('canvas'), {width: 96, height: 64});
      const ctx = canvas.getContext('2d');
      for (const [color, x, y, w, h] of rectangles) {
        ctx.fillStyle = color;
        ctx.fillRect(x, y, w, h);
      }
      return canvas.toDataURL('image/png').split(',')[1];
    }
    return {
      1: png([['#ed3524', 0, 0, 48, 32], ['#7c26d4', 48, 0, 48, 32], ['#1d81bb', 0, 32, 48, 32], ['#ffc116', 48, 32, 48, 32]]),
      2: png([['#165be7', 34, 14, 28, 36]]),
      3: png([['#ffffff', 0, 0, 96, 64], ['#c6333c', 34, 14, 28, 36]]),
      4: png([['#28794a', 0, 0, 96, 64]])
    };
  });
  const requests = [];
  await page.route(base + '/media-library-file-v86.php?*', route => {
    const id = new URL(route.request().url()).searchParams.get('id');
    requests.push(id);
    return route.fulfill({contentType: 'image/png', body: Buffer.from(source[id], 'base64')});
  });
  await page.addScriptTag({path: fileURLToPath(new URL('../chat-marketing-creative-render-v1.js', import.meta.url))});
  const render = (input, points) => page.evaluate(async ({input, points}) => {
    const {blob, width, height} = await window.VP3MarketingCreativeRender.render(input);
    const image = await createImageBitmap(blob);
    const canvas = Object.assign(document.createElement('canvas'), {width, height});
    const ctx = canvas.getContext('2d', {willReadFrequently: true});
    ctx.drawImage(image, 0, 0);
    image.close();
    return {
      width, height, mime: blob.type, bytes: blob.size,
      pixels: points.map(([x, y]) => Array.from(ctx.getImageData(x, y, 1, 1).data))
    };
  }, {input, points});
  const preflight = input => page.evaluate(input => window.VP3MarketingCreativeRender.validateSource(input), input);

  const preserved = await render({...settings, level: 1}, [[155, 85], [925, 85], [155, 595], [925, 595]]);
  assert.equal(preserved.width, 1080);
  assert.equal(preserved.mime, 'image/png');
  assert.ok(preserved.bytes > 1000);
  assert.deepEqual(preserved.pixels, [[237, 53, 36, 255], [124, 38, 212, 255], [29, 129, 187, 255], [255, 193, 22, 255]]);
  assert.deepEqual(requests.splice(0), ['1'], 'Original treatment does not request a generated background');
  const framed = await render({...settings, level: 4, style: 'bold'}, [[0, 0]]);
  assert.notEqual(framed.height, preserved.height);
  assert.deepEqual(framed.pixels[0], [17, 27, 51, 255]);
  requests.length = 0;
  console.log('PASS original artwork preserves all corners and supports distinct levels and design styles');

  const transparent = {...settings, originalUrl: asset(2), level: 5};
  await preflight(transparent);
  const composite = await render(transparent, [[80, 85], [540, 380]]);
  assert.deepEqual(composite.pixels, [[40, 121, 74, 255], [22, 91, 231, 255]]);
  assert.deepEqual(requests.splice(0).sort(), ['2', '2', '4'], 'Scene preflight uses the original only');
  console.log('PASS scene composites the exact transparent product pixels over the generated background');

  const opaque = {...settings, originalUrl: asset(3), level: 6};
  await assert.rejects(preflight(opaque), /Remove solid background/);
  assert.deepEqual(requests.splice(0), ['3'], 'Opaque preflight stops before the generated image is used');
  await preflight({...opaque, removeBackground: true});
  const isolated = await render({...opaque, removeBackground: true}, [[80, 85], [540, 380]]);
  assert.deepEqual(isolated.pixels, [[40, 121, 74, 255], [198, 51, 60, 255]]);
  await assert.rejects(preflight({...settings, level: 7, removeBackground: true}), /corners have different colors/);
  console.log('PASS opaque images require explicit background removal and complex corners fail with guidance');

  requests.length = 0;
  const restaged = await render({...settings, level: 10}, [[540, 380]]);
  assert.deepEqual(restaged.pixels, [[40, 121, 74, 255]]);
  assert.deepEqual(requests.splice(0), ['4'], 'Reference creative renders the generated scene');
  const detailed = await render({...settings, level: 10, headline: 'Blue mug for slow mornings. '.repeat(4).trim(), copy: 'See the glazed finish in your favorite light. '.repeat(12).trim()}, []);
  assert.ok(detailed.height > restaged.height && detailed.height <= 1920);
  const fullWidth = await render({...settings, level: 10, headline: '晨光杯'.repeat(40), copy: '在晨光中欣賞藍色釉面'.repeat(60)}, []);
  assert.ok(fullWidth.height > restaged.height && fullWidth.height <= 1920);
  console.log('PASS generated compositions render with legible wrapped copy and bounded PNG dimensions');

  await assert.rejects(render({...settings, originalUrl: 'https://foreign.test/media-library-file-v86.php?id=1', level: 1}, []), /private Media Library/);
  await assert.rejects(render({...settings, generatedUrl: '/unsafe.php?id=4', level: 10}, []), /private Media Library/);
  await assert.rejects(render({...settings, originalUrl: original + '&id=4', level: 1}, []), /private Media Library/);
  await assert.rejects(render({...settings, level: 7.5}, []), /Review the image treatment/);
  console.log('PASS rejects unowned URLs, ambiguous asset selectors, and invalid treatment levels');
  console.log('CHAT_MARKETING_CREATIVE_RENDER_BROWSER=PASS');
} finally {
  await browser.close();
}
