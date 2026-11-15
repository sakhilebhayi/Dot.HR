import dotPreset from './dot-ui/tailwind/tailwind-preset.cjs';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    // The shared type scale (Roboto, R-52): font-sans is var(--dot-font-sans) and the text-dot-* steps come from dot-ui.
    presets: [dotPreset],

    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    plugins: [forms, typography],
};
