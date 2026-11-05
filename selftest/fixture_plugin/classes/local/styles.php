<?php
namespace local_fixture\local;
/** Styles. */
class styles {
    /**
     * CSS.
     * @param string $css
     * @return string
     */
    public static function css(string $css): string {
        $out = '<textarea name="t">' . $css . '</textarea>'; // EXPECT:S5-php-textarea
        $out .= \html_writer::tag('textarea', $css); // EXPECT:S5-php-textarea
        $out .= \html_writer::tag('textarea', s($css));
        return preg_replace('#</style(?=[\s/>]|$)#i', '', $css); // EXPECT:S5-php-strip
    }
}
