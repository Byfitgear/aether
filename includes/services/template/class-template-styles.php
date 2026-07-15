<?php
/**
 * Template Styles Provider
 * 
 * Provides CSS styles for default templates
 * 
 * @package Aether
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Aether_Template_Styles
 */
class Aether_Template_Styles {
    
    /**
     * Get base styles for menu and navigation
     */
    public static function get_base_styles() {
        return '
            /* Minimal required styles that Tailwind cannot handle */
            .aether-default-template .menu-item-has-children { position: relative; }
            .aether-default-template .menu-item-has-children:hover > .sub-menu { display: block; }
            .aether-default-template .menu-item-has-children > a:after {
                content: " ▼";
                font-size: 0.9em;
                vertical-align: middle;
                opacity: 0.6;
            }
            .aether-default-template .screen-reader-text {
                position: absolute !important;
                clip: rect(1px, 1px, 1px, 1px);
                padding: 0 !important;
                border: 0 !important;
                height: 1px !important;
                width: 1px !important;
                overflow: hidden;
            }
        ';
    }
    
    /**
     * Get content area styles for article content
     * These styles are specifically for WYSIWYG content areas like the_content()
     * and should only be applied on single posts/pages/CPTs
     */
    public static function get_content_styles() {
        return '
            /* Content area styles for WYSIWYG content that Tailwind cannot style */
            /* Applied only to single posts/pages/CPTs within the_content() areas */
            .aether-wysiwyg-content {
                font-size: 1.125rem;
                line-height: 1.75;
                color: #1a1a1a;
            }
            
            /* Headings */
            .aether-wysiwyg-content h1 {
                font-size: 2.5rem;
                font-weight: 700;
                margin-top: 2rem;
                margin-bottom: 1.5rem;
                line-height: 1.2;
            }
            
            .aether-wysiwyg-content h2 {
                font-size: 2rem;
                font-weight: 700;
                margin-top: 2rem;
                margin-bottom: 1.25rem;
                line-height: 1.3;
            }
            
            .aether-wysiwyg-content h3 {
                font-size: 1.5rem;
                font-weight: 600;
                margin-top: 1.75rem;
                margin-bottom: 1rem;
                line-height: 1.4;
            }
            
            .aether-wysiwyg-content h4 {
                font-size: 1.25rem;
                font-weight: 600;
                margin-top: 1.5rem;
                margin-bottom: 0.875rem;
                line-height: 1.5;
            }
            
            .aether-wysiwyg-content h5 {
                font-size: 1.125rem;
                font-weight: 600;
                margin-top: 1.25rem;
                margin-bottom: 0.75rem;
                line-height: 1.5;
            }
            
            .aether-wysiwyg-content h6 {
                font-size: 1rem;
                font-weight: 600;
                margin-top: 1.25rem;
                margin-bottom: 0.75rem;
                line-height: 1.5;
            }
            
            /* Paragraphs */
            .aether-wysiwyg-content p {
                margin-bottom: 1.5rem;
            }
            
            /* Links */
            .aether-wysiwyg-content a {
                color: #0066cc;
                text-decoration: underline;
                text-decoration-thickness: 1px;
                text-underline-offset: 2px;
                transition: color 0.15s ease;
            }
            
            .aether-wysiwyg-content a:hover {
                color: #0052a3;
                text-decoration-thickness: 2px;
            }
            
            /* Blockquotes */
            .aether-wysiwyg-content blockquote {
                margin: 2rem 0;
                padding-left: 1.5rem;
                border-left: 4px solid #d1d5db;
                font-style: italic;
                color: #4b5563;
            }
            
            .aether-wysiwyg-content blockquote p:last-child {
                margin-bottom: 0;
            }
            
            /* Lists */
            .aether-wysiwyg-content ul,
            .aether-wysiwyg-content ol {
                margin-bottom: 1.5rem;
                padding-left: 2rem;
            }
            
            .aether-wysiwyg-content ul {
                list-style-type: disc;
            }
            
            .aether-wysiwyg-content ol {
                list-style-type: decimal;
            }
            
            .aether-wysiwyg-content li {
                margin-bottom: 0.5rem;
            }
            
            .aether-wysiwyg-content ul ul,
            .aether-wysiwyg-content ol ol,
            .aether-wysiwyg-content ul ol,
            .aether-wysiwyg-content ol ul {
                margin-top: 0.5rem;
                margin-bottom: 0.5rem;
            }
            
            /* Code */
            .aether-wysiwyg-content code {
                font-family: "Consolas", "Monaco", "Courier New", monospace;
                font-size: 0.875em;
                background-color: #f3f4f6;
                padding: 0.125rem 0.375rem;
                border-radius: 0.25rem;
                color: #dc2626;
            }
            
            .aether-wysiwyg-content pre {
                margin: 1.5rem 0;
                padding: 1rem;
                background-color: #f8f9fa;
                border: 1px solid #e5e7eb;
                border-radius: 0.375rem;
                overflow-x: auto;
                font-size: 0.875rem;
                line-height: 1.5;
            }
            
            .aether-wysiwyg-content pre code {
                background-color: transparent;
                padding: 0;
                color: inherit;
                font-size: inherit;
            }
            
            /* Tables */
            .aether-wysiwyg-content table {
                width: 100%;
                margin: 2rem 0;
                border-collapse: collapse;
                font-size: 0.95rem;
            }
            
            .aether-wysiwyg-content th,
            .aether-wysiwyg-content td {
                padding: 0.75rem 1rem;
                text-align: left;
                border-bottom: 1px solid #e5e7eb;
            }
            
            .aether-wysiwyg-content th {
                font-weight: 600;
                background-color: #f9fafb;
                border-top: 1px solid #e5e7eb;
            }
            
            .aether-wysiwyg-content tbody tr:hover {
                background-color: #f9fafb;
            }
            
            /* Images and Figures */
            .aether-wysiwyg-content img {
                max-width: 100%;
                height: auto;
                margin: 2rem auto;
                display: block;
                border-radius: 0.5rem;
            }
            
            .aether-wysiwyg-content figure {
                margin: 2rem 0;
                text-align: center;
            }
            
            .aether-wysiwyg-content figure img {
                margin: 0 auto 0.5rem;
            }
            
            .aether-wysiwyg-content figcaption {
                font-size: 0.875rem;
                color: #6b7280;
                font-style: italic;
                margin-top: 0.5rem;
            }
            
            /* WordPress specific - Gallery */
            .aether-wysiwyg-content .gallery {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 1rem;
                margin: 2rem 0;
            }
            
            .aether-wysiwyg-content .gallery-item {
                margin: 0;
            }
            
            .aether-wysiwyg-content .gallery-icon img {
                margin: 0;
                border-radius: 0.375rem;
            }
            
            /* WordPress specific - Alignments */
            .aether-wysiwyg-content .alignleft {
                float: left;
                margin-right: 1.5rem;
                margin-bottom: 1rem;
            }
            
            .aether-wysiwyg-content .alignright {
                float: right;
                margin-left: 1.5rem;
                margin-bottom: 1rem;
            }
            
            .aether-wysiwyg-content .aligncenter {
                display: block;
                margin-left: auto;
                margin-right: auto;
            }
            
            .aether-wysiwyg-content .alignfull {
                width: 100vw;
                position: relative;
                left: 50%;
                right: 50%;
                margin-left: -50vw;
                margin-right: -50vw;
                max-width: 100vw;
            }
            
            .aether-wysiwyg-content .alignwide {
                position: relative;
                width: calc(100% + 8rem);
                max-width: calc(100% + 8rem);
                margin-left: -4rem;
                margin-right: -4rem;
            }
            
            /* WordPress specific - Captions */
            .aether-wysiwyg-content .wp-caption {
                max-width: 100%;
                margin: 2rem 0;
            }
            
            .aether-wysiwyg-content .wp-caption-text {
                font-size: 0.875rem;
                color: #6b7280;
                font-style: italic;
                text-align: center;
                margin-top: 0.5rem;
            }
            
            /* Horizontal Rule */
            .aether-wysiwyg-content hr {
                margin: 3rem 0;
                border: none;
                border-top: 1px solid #e5e7eb;
            }
            
            /* Definition Lists */
            .aether-wysiwyg-content dl {
                margin-bottom: 1.5rem;
            }
            
            .aether-wysiwyg-content dt {
                font-weight: 600;
                margin-bottom: 0.25rem;
            }
            
            .aether-wysiwyg-content dd {
                margin-left: 2rem;
                margin-bottom: 1rem;
            }
            
            /* Footnotes */
            .aether-wysiwyg-content .footnotes {
                margin-top: 3rem;
                padding-top: 2rem;
                border-top: 1px solid #e5e7eb;
                font-size: 0.875rem;
                color: #6b7280;
            }
            
            .aether-wysiwyg-content .footnotes ol {
                padding-left: 1.25rem;
            }
            
            .aether-wysiwyg-content sup {
                font-size: 0.75em;
                vertical-align: super;
            }
            
            /* Embeds */
            .aether-wysiwyg-content iframe,
            .aether-wysiwyg-content embed,
            .aether-wysiwyg-content object,
            .aether-wysiwyg-content video {
                max-width: 100%;
                margin: 2rem 0;
            }
            
            /* WordPress Block Editor - Buttons */
            .aether-wysiwyg-content .wp-block-button__link {
                display: inline-block;
                padding: 0.5rem 1.25rem;
                background-color: #1f2937;
                color: white;
                text-decoration: none;
                border-radius: 0.25rem;
                transition: background-color 0.15s ease;
            }
            
            .aether-wysiwyg-content .wp-block-button__link:hover {
                background-color: #111827;
                text-decoration: none;
            }
            
            /* Strong and Emphasis */
            .aether-wysiwyg-content strong,
            .aether-wysiwyg-content b {
                font-weight: 600;
            }
            
            .aether-wysiwyg-content em,
            .aether-wysiwyg-content i {
                font-style: italic;
            }
            
            /* Small text */
            .aether-wysiwyg-content small {
                font-size: 0.875em;
            }
            
            /* Mark/Highlight */
            .aether-wysiwyg-content mark {
                background-color: #fef3c7;
                padding: 0.125rem 0.25rem;
            }
            
            /* Abbreviations */
            .aether-wysiwyg-content abbr[title] {
                text-decoration: underline dotted;
                cursor: help;
            }
            
            /* Keyboard input */
            .aether-wysiwyg-content kbd {
                font-family: "Consolas", "Monaco", "Courier New", monospace;
                font-size: 0.875em;
                padding: 0.125rem 0.375rem;
                background-color: #f3f4f6;
                border: 1px solid #d1d5db;
                border-radius: 0.25rem;
                box-shadow: 0 1px 0 #d1d5db;
            }
            
            /* Responsive adjustments */
            @media (max-width: 768px) {
                .aether-wysiwyg-content {
                    font-size: 1rem;
                }
                
                .aether-wysiwyg-content h1 {
                    font-size: 2rem;
                }
                
                .aether-wysiwyg-content h2 {
                    font-size: 1.5rem;
                }
                
                .aether-wysiwyg-content h3 {
                    font-size: 1.25rem;
                }
                
                .aether-wysiwyg-content .alignwide {
                    width: 100%;
                    margin-left: 0;
                    margin-right: 0;
                }
            }
        ';
    }
    
    /**
     * Get all styles combined
     * @deprecated Use get_base_styles() and get_content_styles() separately
     */
    public static function get_all_styles() {
        return self::get_base_styles() . self::get_content_styles();
    }
    
    /**
     * Check if current page should have content styles
     * @return bool
     */
    public static function should_include_content_styles() {
        // Only include on singular pages (single posts, pages, CPTs)
        return is_singular();
    }
    
    /**
     * Get styles appropriate for current context
     * @return string
     */
    public static function get_contextual_styles() {
        $styles = self::get_base_styles();
        
        // Only add content styles on singular pages
        if (self::should_include_content_styles()) {
            $styles .= self::get_content_styles();
        }
        
        return $styles;
    }
}