const { src, dest, watch, series } = require('gulp');
const sass = require('gulp-sass')(require('sass'));

function styles() {
    return src('assets/scss/admin.scss')
        .pipe(sass().on('error', sass.logError))
        .pipe(dest('assets/css'));
}

function watcher() {
    watch('assets/scss/**/*.scss', styles);
}

exports.styles = styles;
exports.default = series(styles, watcher);
