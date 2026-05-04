/**
 * EMSP Docs - Asset Fix Script
 * Run with: node _fix_assets.js
 * 
 * This script:
 * 1. Downloads fresh Bootstrap Icons font files (woff2 + woff) to fix OTS parsing errors
 * 2. Copies generated PWA icons to the correct location
 */
const https = require('https');
const fs = require('fs');
const path = require('path');

const FONTS_DIR = path.join(__dirname, 'assets', 'fonts');
const IMAGES_DIR = path.join(__dirname, 'assets', 'images');

function download(url, dest) {
    return new Promise((resolve, reject) => {
        console.log(`  Downloading: ${url}`);
        const file = fs.createWriteStream(dest);
        https.get(url, (response) => {
            // Follow redirects
            if (response.statusCode >= 300 && response.statusCode < 400 && response.headers.location) {
                file.close();
                fs.unlinkSync(dest);
                return download(response.headers.location, dest).then(resolve).catch(reject);
            }
            if (response.statusCode !== 200) {
                file.close();
                fs.unlinkSync(dest);
                return reject(new Error(`HTTP ${response.statusCode} for ${url}`));
            }
            response.pipe(file);
            file.on('finish', () => {
                file.close();
                const stats = fs.statSync(dest);
                console.log(`  -> Saved: ${dest} (${stats.size} bytes)`);
                resolve();
            });
        }).on('error', (err) => {
            file.close();
            if (fs.existsSync(dest)) fs.unlinkSync(dest);
            reject(err);
        });
    });
}

function copyIfExists(src, dest) {
    if (fs.existsSync(src)) {
        fs.copyFileSync(src, dest);
        console.log(`  Copied: ${src} -> ${dest}`);
        return true;
    }
    console.log(`  Not found (skip): ${src}`);
    return false;
}

async function main() {
    console.log('=== EMSP Docs Asset Fix ===\n');

    // 1. Download fresh Bootstrap Icons font files
    console.log('1. Downloading fresh Bootstrap Icons fonts...');
    try {
        await download(
            'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2',
            path.join(FONTS_DIR, 'bootstrap-icons.woff2')
        );
        await download(
            'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff',
            path.join(FONTS_DIR, 'bootstrap-icons.woff')
        );
        console.log('  Fonts downloaded successfully!\n');
    } catch (err) {
        console.error('  ERROR downloading fonts:', err.message);
        console.log('  Manual fix: download from https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/\n');
    }

    // 2. Copy generated PWA icons
    console.log('2. Copying PWA manifest icons...');
    const geminiDir = path.join(
        process.env.USERPROFILE || process.env.HOME || '',
        '.gemini', 'antigravity', 'brain', 'f055c60d-f9c6-4865-8535-cc70e51d7552'
    );
    
    // Find the generated icon files
    let copied192 = false;
    let copied512 = false;
    
    if (fs.existsSync(geminiDir)) {
        const files = fs.readdirSync(geminiDir);
        for (const f of files) {
            if (f.startsWith('logo_emsp_192') && f.endsWith('.png')) {
                copyIfExists(path.join(geminiDir, f), path.join(IMAGES_DIR, 'logo-emsp-192.png'));
                copied192 = true;
            }
            if (f.startsWith('logo_emsp_512') && f.endsWith('.png')) {
                copyIfExists(path.join(geminiDir, f), path.join(IMAGES_DIR, 'logo-emsp-512.png'));
                copied512 = true;
            }
        }
    }
    
    // If generated icons not found, create from existing logo
    if (!copied192 || !copied512) {
        const srcLogo = path.join(IMAGES_DIR, 'logo-emsp.png');
        if (!copied192) copyIfExists(srcLogo, path.join(IMAGES_DIR, 'logo-emsp-192.png'));
        if (!copied512) copyIfExists(srcLogo, path.join(IMAGES_DIR, 'logo-emsp-512.png'));
    }
    
    console.log('\n=== Done! Refresh your browser to verify fixes. ===');
}

main().catch(console.error);
