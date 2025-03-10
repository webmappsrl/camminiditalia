let mix = require('laravel-mix')
let NovaExtension = require('laravel-nova-devtool')

mix.extend('nova', new NovaExtension())

mix
  .setPublicPath('dist')
  .js('resources/js/field.js', 'js')
  .vue({ version: 3 })
  .css('resources/css/field.css', 'css')
  .nova('wm/layer-features')
  .version()

mix.copy("node_modules/ag-grid-community/styles/ag-grid.css", "dist/css/ag-grid.css")
  .copy("node_modules/ag-grid-community/styles/ag-theme-alpine.css", "dist/css/ag-theme-alpine.css");