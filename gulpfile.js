const gulp = require('gulp');
const replace = require("gulp-replace");

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

// replace_text_domain ////////////////////////////////////////////////
gulp.task("replace_text_domain", function(done) {
	// vk-admin
	gulp.src(["./inc/vk-admin/package/*"])
		.pipe(replace("vk_admin_textdomain","vk-plugin-beta-tester"))
		.pipe(gulp.dest("./inc/vk-admin/package/"));
	done();
});
