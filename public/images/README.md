# JARVIS Application Assets

This directory contains all visual assets for the JARVIS AI Command Center application.

## App Icons (`/app_icons/`)

Application icons in multiple sizes for cross-platform support:

### Standard Icons
- **icon-16x16.svg** - Favicon, browser tab icon
- **icon-32x32.svg** - Favicon, browser tab icon (standard)
- **icon-48x48.svg** - Windows taskbar
- **icon-72x72.svg** - Windows tiles (small)
- **icon-96x96.svg** - Android launcher (ldpi)
- **icon-144x144.svg** - Windows tiles (medium), iOS Spotlight
- **icon-192x192.svg** - Android launcher (xxxhdpi), PWA standard
- **icon-512x512.svg** - Android launcher (xxxhdpi), PWA splash, Play Store

### Platform-Specific Icons
- **apple-touch-icon.svg** (180x180) - iOS home screen icon, Safari pinned tab
- **favicon.svg** (32x32) - Root favicon, modern browser support

### Usage in HTML
```html
<!-- Favicons -->
<link rel="icon" type="image/svg+xml" href="/favicon.svg" />
<link rel="icon" type="image/png" sizes="32x32" href="/images/app_icons/icon-32x32.svg" />
<link rel="icon" type="image/png" sizes="16x16" href="/images/app_icons/icon-16x16.svg" />

<!-- Apple Touch Icon -->
<link rel="apple-touch-icon" sizes="180x180" href="/images/app_icons/apple-touch-icon.svg" />

<!-- PWA Manifest -->
<link rel="manifest" href="/manifest.json" />
```

## Social Media Preview Images (`/social/`)

Optimized preview images for social media sharing:

### Open Graph (Facebook, LinkedIn, etc.)
- **og-image.svg** (1200x630) - Main landing page preview
- **og-image-login.svg** (1200x630) - Login page preview
- Optimal size: 1200x630px
- Aspect ratio: 1.91:1

### Twitter Card
- **twitter-card.svg** (1200x600) - Twitter-specific preview
- Optimal size: 1200x600px
- Aspect ratio: 2:1

### Usage in HTML
```html
<!-- Open Graph -->
<meta property="og:image" content="https://jarvis.simplefunctioningsolutions.com/images/social/og-image.svg" />
<meta property="og:image:width" content="1200" />
<meta property="og:image:height" content="630" />

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:image" content="https://jarvis.simplefunctioningsolutions.com/images/social/og-image.svg" />
```

## Core Assets (`/images/`)

### Branding
- **logo.svg** (512x512) - Main JARVIS logo with hexagon design
- **logo_option_1.svg** - Alternative logo variant
- **logo_option_2.svg** - Alternative logo variant  
- **logo_option_3.svg** - Alternative logo variant

### Illustrations
- **hero.svg** - Hero section illustration/background

## Icon Size Reference

### iOS Sizes
- 180x180 - iPhone (Retina)
- 152x152 - iPad (Retina)
- 144x144 - iPad
- 120x120 - iPhone
- 76x76 - iPad (non-Retina)
- 72x72 - iPad (legacy)

### Android Sizes
- 512x512 - Play Store, PWA splash screens
- 192x192 - xxxhdpi (4x)
- 144x144 - xxhdpi (3x)
- 96x96 - xhdpi (2x)
- 72x72 - hdpi (1.5x)
- 48x48 - mdpi (1x)

### Web/Desktop
- 32x32 - Standard favicon
- 16x16 - Bookmark icon, legacy browsers

## PWA Manifest

The `manifest.json` file in the public root defines the app for Progressive Web App installation:
- App name and short name
- Display mode (standalone)
- Theme colors
- Icon definitions for all sizes
- Screenshots for app stores

## Browser Configuration

The `browserconfig.xml` file defines Windows tile configuration:
- Tile colors
- Tile image sizes (70x70, 150x150, 310x310)

## Design Specifications

### Color Palette
- Primary Background: `#050814` (Deep navy)
- Panel Background: `#07122a` (Dark blue)
- Primary Blue: `#1e78ff`
- Accent Cyan: `#00d4ff` / `#22d3ee`
- Text Light: `#eaf2ff`
- Text Muted: `#9bb4d6`

### Effects
- Gaussian blur glow filters for depth
- Hexagonal geometric shapes
- Grid patterns with low opacity
- Gradient backgrounds

### Typography
- Primary Font: Segoe UI, Arial, system UI
- Weights: 600 (semibold), 700 (bold), 800 (extra bold)
- Letter spacing on labels and badges

## Optimization Notes

All assets are currently in SVG format for:
- **Scalability**: Resolution-independent, perfect at any size
- **Small file size**: Text-based format, compresses well
- **Flexibility**: Easy to modify colors, effects, and styling
- **Modern browser support**: All major browsers support SVG

For maximum compatibility, consider generating PNG versions:
```bash
# Using ImageMagick or similar tool
convert icon.svg -resize 512x512 icon-512x512.png
```

## Validation

Test your meta tags and social previews:
- **Facebook Debugger**: https://developers.facebook.com/tools/debug/
- **Twitter Card Validator**: https://cards-dev.twitter.com/validator
- **LinkedIn Post Inspector**: https://www.linkedin.com/post-inspector/
- **Open Graph Check**: https://www.opengraph.xyz/

---

**Last Updated**: January 13, 2026  
**Maintained By**: Simple Functioning Solutions  
**Application**: JARVIS AI Command Center
