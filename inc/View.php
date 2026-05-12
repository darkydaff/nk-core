<?php
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class View {
  private static ?Environment $twig = null;

  public static function init(string $templatesPath, array $globals = []): void {
    if (!class_exists(Environment::class)) {
      throw new RuntimeException('Twig is not installed. Run composer require twig/twig');
    }
    $loader = new FilesystemLoader($templatesPath);
    $cachePath = dirname(__DIR__) . '/storage/cache/twig';
    if (!is_dir($cachePath)) {
      @mkdir($cachePath, 0775, true);
    }
    self::$twig = new Environment($loader, [
      'cache' => $cachePath,
      'auto_reload' => true,
      'autoescape' => 'html',
    ]);

    // Add translation function
    $tFunc = new TwigFunction('t', function (string $key, array $params = [], ?string $default = null) {
      return Translator::t($key, $params, $default);
    });
    self::$twig->addFunction($tFunc);

    // Add flag emoji function
    $flagFunc = new TwigFunction('getFlag', [self::class, 'getFlag']);
    self::$twig->addFunction($flagFunc);

    // Add globals
    foreach ($globals as $k => $v) self::$twig->addGlobal($k, $v);
  }

  public static function render(string $template, array $vars = []): void {
    if (!self::$twig) throw new RuntimeException('Twig is not initialized');
    echo self::$twig->render($template, $vars);
  }

  public static function getFlag(string $code): string {
    if (empty($code)) return '';
    $code = strtoupper($code);
    $map = ['EN' => 'GB', 'UK' => 'UA'];
    if (isset($map[$code])) $code = $map[$code];
    
    // Convert CC to Regional Indicator Symbols
    $emoji = '';
    for ($i = 0; $i < strlen($code); $i++) {
        $emoji .= mb_chr(127397 + ord($code[$i]), 'UTF-8');
    }
    
    return '<i class="twf twf-' . strtolower($code) . '" title="' . $code . '">' . $emoji . '</i>';
  }
}