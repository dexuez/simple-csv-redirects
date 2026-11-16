const { src, dest, watch, parallel, series } = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const terser = require('gulp-terser');

function styles() {
    return src('assets/src/scss/admin.scss')
        .pipe(sass({ style: 'compressed' }).on('error', sass.logError))
        .pipe(dest('assets/dist'));
}

function scripts() {
    return src('assets/src/js/admin.js')
        .pipe(terser())
        .pipe(dest('assets/dist'));
}

function watcher() {
    watch('assets/src/scss/**/*.scss', styles);
    watch('assets/src/js/**/*.js', scripts);
}

const build = parallel(styles, scripts);

exports.styles = styles;
exports.scripts = scripts;
exports.build = build;
exports.default = series(build, watcher);
