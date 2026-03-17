import js from "@eslint/js";
import globals from "globals";

export default [
  {
    ignores: ["node_modules/**"],
  },
  js.configs.recommended,
  {
    files: ["assets/js/**/*.js"],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: "script",
      globals: {
        ...globals.browser,
        ...globals.es2021,
        L: "readonly",
        linkify: "readonly",
      },
    },
    rules: {
      "no-misleading-character-class": "off",
      "no-prototype-builtins": "off",
      "no-unused-vars": "off",
      "no-undef": "error"
    },
  },
];