<?php

class cli
{
    const MENU = "(l) Listar conexões          
(t) Listar troncos           
(c) Listar contas            
(b) Banir IP                 
(d) Desbanir IP              
(i) Listar IPs banidos
(p) Trocar Porta do Servidor Web
(e) Executar EVAL-CODE
(r) Reiniciar Servidor Web
(a) Listar chamadas        
(q) Encerrar servidor        " . PHP_EOL;

    public static function show(): void
    {
        print self::MENU;
    }

    public static function color($color, $message): string
    {
        $colors = [
            'black' => '0;30',
            'red' => '0;31',
            'green' => '0;32',
            'yellow' => '0;33',
            'blue' => '0;34',
            'magenta' => '0;35',
            'cyan' => '0;36',
            'white' => '0;37',
            'bold_black' => '1;30',
            'bold_red' => '1;31',
            'bold_green' => '1;32',
            'bold_yellow' => '1;33',
            'bold_blue' => '1;34',
            'bold_magenta' => '1;35',
            'bold_cyan' => '1;36',
            'bold_white' => '1;37'
        ];

        $colorCode = $colors[$color] ?? '0';
        return "\033[" . $colorCode . "m" . $message . "\033[0m";
    }

    public static function menuCallback($menuCallback): void
    {

        while (true) {
            shell_exec('stty -icanon');
            $key = strtolower(fread(STDIN, 3));
            if ($key === "\n") {
                print self::MENU . PHP_EOL;
                continue;
            } else if (in_array($key, [
                "\033[A",
                "\033[B",
                "\033[C",
                "\033[D",
            ])) {
                print shell_exec('clear');
                print self::MENU . PHP_EOL;
                continue;
            }
            print shell_exec('clear');
            if (isset($menuCallback[$key])) {
                $r = $menuCallback[$key]();
                if ($r === 'break') break;
            } else {
                print self::MENU . PHP_EOL;
            }
        }
    }

    public static function pcl(string $message, string $color = 'white'): void
    {
        $colors = [
            'black' => '0;30',
            'red' => '0;31',
            'green' => '0;32',
            'yellow' => '0;33',
            'blue' => '0;34',
            'magenta' => '0;35',
            'cyan' => '0;36',
            'white' => '0;37',
            'bold_black' => '1;30',
            'bold_red' => '1;31',
            'bold_green' => '1;32',
            'bold_yellow' => '1;33',
            'bold_blue' => '1;34',
            'bold_magenta' => '1;35',
            'bold_cyan' => '1;36',
            'bold_white' => '1;37'
        ];

        $colorCode = $colors[$color] ?? '0';
        print "\033[" . $colorCode . "m" . $message . "\033[0m" . "\n";
    }


    public static function cl(string $color, string $message): string
    {
        $colors = [
            'black' => '0;30',
            'red' => '0;31',
            'green' => '0;32',
            'yellow' => '0;33',
            'blue' => '0;34',
            'magenta' => '0;35',
            'cyan' => '0;36',
            'white' => '0;37',
            'bold_black' => '1;30',
            'bold_red' => '1;31',
            'bold_green' => '1;32',
            'bold_yellow' => '1;33',
            'bold_blue' => '1;34',
            'bold_magenta' => '1;35',
            'bold_cyan' => '1;36',
            'bold_white' => '1;37'
        ];

        $colorCode = $colors[$color] ?? '0';
        return "\033[" . $colorCode . "m" . $message . "\033[0m" . "\n";
    }
}