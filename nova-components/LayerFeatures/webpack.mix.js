const mix = require('laravel-mix')

mix
  .setPublicPath('dist')
  .js('resources/js/field.js', 'js')
  .vue({ version: 3 })
  .ts('resources/js/field.ts', 'js')
  .sass('resources/sass/field.scss', 'css')
  .css('resources/css/field.css', 'css')
  .version()
  .webpackConfig({
    externals: {
      vue: 'Vue',
    },
    output: {
      uniqueName: 'wm/layer-features',
    },
    module: {
      rules: [
        {
          test: /\.tsx?$/,
          loader: 'ts-loader',
          options: {
            appendTsSuffixTo: [/\.vue$/],
            transpileOnly: true,
          },
          exclude: /node_modules/,
        },
      ],
    },
    resolve: {
      extensions: ['.ts', '.tsx', '.js', '.jsx', '.vue', '.json'],
    },
  })

mix.copy("node_modules/ag-grid-community/styles/ag-grid.css", "dist/css/ag-grid.css")
  .copy("node_modules/ag-grid-community/styles/ag-theme-alpine.css", "dist/css/ag-theme-alpine.css");