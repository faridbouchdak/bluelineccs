/** @type {import('tailwindcss').Config} */
export default {
    content: [
      "./**/*.html",
      "./assets/js/*.js"
    ],
    theme: {
      extend: {
        colors: {
          blueline: {
              light: '#4AA9DE',
              DEFAULT: '#2176FF',
              dark: '#1B4B8A',
              gray: '#666666',
              lightgray: '#F5F7FA'
          }
        }
      },
    },
    plugins: [],
  }