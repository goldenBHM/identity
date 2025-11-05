import terser from "@rollup/plugin-terser";
import path from "path";

export default {
  input: path.resolve("js/index.js"),
  output: {
    file: "dist/fingerprint.min.js",
    format: "iife", // Immediately Invoked Function Expression (works in browsers)
    name: "BhmFP",
  },
  plugins: [terser()],
};
