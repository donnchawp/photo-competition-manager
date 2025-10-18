# 📸 Club Competitions Plugin - Shortcode Usage Guide

This guide explains how to use the Club Competitions plugin shortcodes to display competition functionality on your WordPress site.

## Available Shortcodes

### 1. Upload Form
**Shortcode:** `[competition_upload competition="slug"]`
**Purpose:** Allows members to upload images for a specific competition.

**Example:**
```php
[competition_upload competition="october-2024"]
```

**Features:**
- Member email verification
- Optional competition password gate before uploads
- Category selection
- File upload with validation
- Automatic image processing and thumbnails

---

### 2. Voting Interface
**Shortcode:** `[competition_voting competition="slug"]`
**Purpose:** Displays voting interface for members to vote on submitted images.

**Example:**
```php
[competition_voting competition="october-2024"]
```

**Features:**
- Mobile-optimized grid layout
- Position-based scoring (1st, 2nd, 3rd, etc.)
- Voter name collection
- Optional competition password gate before submitting votes
- Real-time vote validation

---

### 3. Complete Results Table
**Shortcode:** `[competition_results]` or `[competition_results competition="slug"]`
**Purpose:** Shows all competition results grouped by grade, sorted by total scores.

**Parameters:**
- `competition` - Competition slug (optional, defaults to most recent)
- `hide_names` - Set to "true" to hide member names (optional, defaults to "false")

**Examples:**
```php
// Show results for most recent competition
[competition_results]

// Show results for specific competition
[competition_results competition="october-2024"]

// Show results without member names (anonymous display)
[competition_results hide_names="true"]
[competition_results competition="october-2024" hide_names="true"]
```

**Features:**
- **Category Grouping:** Results organized by category (Colour, Black & White, etc.)
- **Grade Sub-Grouping:** Within each category, results grouped by grade (Beginner, Intermediate, Advanced)
- **Score Sorting:** Highest to lowest scores within each grade
- **Rich Display:** Position, thumbnail, member name, score, vote count
- **Responsive Design:** Mobile-friendly table layout
- **Interactive:** Clickable thumbnails open full-size images

**Display Format:**
```
Category: Colour
├─ Grade: Beginner
│  ┌─────────┬────────┬─────────────┬───────┬───────┐
│  │ Position│ Image  │ Member      │ Score │ Votes │
│  ├─────────┼────────┼─────────────┼───────┼───────┤
│  │    1    │ [img]  │ John Smith  │ 8.5   │  12   │
│  │    2    │ [img]  │ Jane Doe    │ 7.8   │  10   │
│  └─────────┴────────┴─────────────┴───────┴───────┘
├─ Grade: Intermediate
│  ┌─────────┬────────┬─────────────┬───────┬───────┐
│  │ Position│ Image  │ Member      │ Score │ Votes │
│  ├─────────┼────────┼─────────────┼───────┼───────┤
│  │    1    │ [img]  │ Bob Wilson  │ 8.2   │  11   │
│  └─────────┴────────┴─────────────┴───────┴───────┘

Category: Black & White
├─ Grade: Beginner
│  ┌─────────┬────────┬─────────────┬───────┬───────┐
│  │ Position│ Image  │ Member      │ Score │ Votes │
│  ├─────────┼────────┼─────────────┼───────┼───────┤
│  │    1    │ [img]  │ Alice Brown │ 7.9   │   9   │
│  └─────────┴────────┴─────────────┴───────┴───────┘
```

---

### 4. Top 3 Podium Display
**Shortcode:** `[competition_top3]` or `[competition_top3 competition="slug"]`
**Purpose:** Shows the top 3 winners for each grade in a podium-style layout.

**Examples:**
```php
// Show top 3 for most recent competition
[competition_top3]

// Show top 3 for specific competition
[competition_top3 competition="october-2024"]
```

**Features:**
- **Category Grouping:** Results organized by category first
- **Grade Sub-Grouping:** Within each category, separate podiums for each grade
- **Podium Layout:** 1st, 2nd, and 3rd place visual arrangement
- **Color Coding:** Gold, silver, and bronze styling
- **Rich Information:** Thumbnails, member names, scores
- **Responsive:** Stacks vertically on mobile devices

**Display Format:**
```
Category: Colour
├─ Grade: Beginner
│  ┌─────────────────────────────────────────────────────────────┐
│  │                    🥇 1st Place                            │
│  │                   [Image Thumbnail]                        │
│  │                   John Smith                               │
│  │                   Score: 8.5 (12 votes)                    │
│  ├─────────────────────────────────────────────────────────────┤
│  │                    🥈 2nd Place                            │
│  │                   [Image Thumbnail]                        │
│  │                   Jane Doe                                 │
│  │                   Score: 7.8 (10 votes)                    │
│  └─────────────────────────────────────────────────────────────┘
├─ Grade: Intermediate
│  ┌─────────────────────────────────────────────────────────────┐
│  │                    🥇 1st Place                            │
│  │                   [Image Thumbnail]                        │
│  │                   Bob Wilson                               │
│  │                   Score: 8.2 (11 votes)                    │
│  └─────────────────────────────────────────────────────────────┘

Category: Black & White
├─ Grade: Beginner
│  ┌─────────────────────────────────────────────────────────────┐
│  │                    🥇 1st Place                            │
│  │                   [Image Thumbnail]                        │
│  │                   Alice Brown                              │
│  │                   Score: 7.9 (9 votes)                     │
│  └─────────────────────────────────────────────────────────────┘
```

---

## Implementation Examples

### Basic Competition Page
Create a page with the following content:

```php
<h2>October 2024 Competition</h2>

<h3>Upload Your Images</h3>
[competition_upload competition="october-2024"]

<h3>Vote on Submissions</h3>
[competition_voting competition="october-2024"]
```

### Results Page (Most Recent Competition)
Create a results page that automatically shows the latest competition:

```php
<h1>Latest Competition Results</h1>

<h2>Top 3 Winners</h2>
[competition_top3]

<h2>Complete Results</h2>
[competition_results]
```

### Results Page (Specific Competition)
Create a dedicated results page for a specific competition:

```php
<h1>October 2024 Competition Results</h1>

<h2>Top 3 Winners</h2>
[competition_top3 competition="october-2024"]

<h2>Complete Results</h2>
[competition_results competition="october-2024"]
```

### Archive Page
Create an archive page listing all competitions:

```php
<h1>Competition Archive</h1>

<h2>September 2024</h2>
[competition_results competition="september-2024"]

<h2>August 2024</h2>
[competition_results competition="august-2024"]
```

---

## Styling and Customization

The shortcodes include comprehensive CSS styling that:
- Matches WordPress admin color scheme
- Provides responsive design for all devices
- Includes hover effects and visual hierarchy
- Uses professional typography and spacing

### Custom CSS
You can override the default styles by adding custom CSS to your theme:

```css
/* Customize results table */
.club-competitions-results table {
    border: 2px solid #your-color;
}

/* Customize podium display */
.podium-item.first {
    background: linear-gradient(135deg, #your-gold, #your-light-gold);
}
```

---

## Best Practices

### 1. Competition Slug Naming
Use consistent, URL-friendly slugs:
- ✅ `october-2024`
- ✅ `spring-competition-2024`
- ❌ `October 2024 Competition!`

### 2. Page Organization
- **Upload Page:** Use only the upload shortcode
- **Voting Page:** Use only the voting shortcode
- **Results Page:** Use both results shortcodes for comprehensive display
- **Archive Page:** Use results shortcodes for multiple competitions

### 3. Mobile Optimization
- All shortcodes are mobile-responsive
- Test on various devices before publishing
- Consider using the top3 shortcode for mobile results display

### 4. Performance
- Results shortcodes cache data efficiently
- Large competitions (100+ images) may take a moment to load
- Consider pagination for very large result sets

---

## Troubleshooting

### Common Issues

**Shortcode not displaying:**
- Check that the competition slug is correct
- Ensure the competition exists and is published
- Verify the shortcode syntax (no extra spaces)

**Images not showing:**
- Check file permissions in uploads directory
- Verify image files exist in the correct folder structure
- Check for JavaScript errors in browser console

**Styling issues:**
- Ensure the plugin's CSS is loading
- Check for theme CSS conflicts
- Verify responsive breakpoints

### Support
For additional help:
- Check the plugin's admin dashboard for competition status
- Review the WordPress error logs
- Contact the plugin support team with specific error messages

---

## Advanced Usage

### Conditional Display
You can use WordPress conditional tags to show different content:

```php
<?php if (is_user_logged_in()): ?>
    [competition_upload competition="october-2024"]
<?php else: ?>
    <p>Please log in to upload images.</p>
<?php endif; ?>
```

### Multiple Competitions
Display multiple competitions on the same page:

```php
<h2>Current Competitions</h2>
[competition_results competition="october-2024"]

<h2>Previous Results</h2>
[competition_results competition="september-2024"]
```

This guide provides everything you need to effectively use the Club Competitions plugin shortcodes on your WordPress site.
