const gulp = require('gulp');
const replace = require("gulp-replace");

// 同期的に処理してくれる（ distで使用している ）
var runSequence = require('run-sequence');

gulp.task('dist', function() {
    return gulp.src(
            [
							'./inc/**',
							'./admin/**',
							'./vendor/**',
							'./*.php',
							'./*.txt',
							'./*.png',
            ],
            { base: './' }
        )
        .pipe( gulp.dest( 'dist/vk-plugin-beta-tester' ) ); // distディレクトリに出力
} );