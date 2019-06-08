var gulp = require('gulp');

// 同期的に処理してくれる（ distで使用している ）
var runSequence = require('run-sequence');

gulp.task('dist', function() {
    return gulp.src(
            [
							'./**/*.php',
							'./**/*.txt',
							'./**/*.css',
							'./**/*.scss',
							'./**/*.bat',
							'./**/*.rb',
							'./**/*.eot',
							'./**/*.svg',
							'./**/*.ttf',
							'./**/*.woff',
							'./**/*.woff2',
							'./**/*.otf',
							'./**/*.less',
							'./**/*.png',
							'./images/**',
							'./inc/**',
							'./assets/**',
							'./admin/**',
							'./languages/**',
							"!./compile.bat",
							"!./config.rb",
							"!./tests/**",
							"!./dist/**",
							"!./node_modules/**"
            ],
            { base: './' }
        )
        .pipe( gulp.dest( 'dist/vk-plugin-beta-tester' ) ); // distディレクトリに出力
} );
