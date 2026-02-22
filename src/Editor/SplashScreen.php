<?php

namespace Sendama\Console\Editor;

use Atatusoft\Termutil\IO\Console\Console;
use Atatusoft\Termutil\IO\Console\Cursor;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Represents the splash screen.
 */
final readonly class SplashScreen
{
    public const int SPLASH_SCREEN_DURATION = 3; // In seconds

    public function __construct(
        private Cursor $cursor,
        private OutputInterface $output,
        private GameSettings $settings,
    )
    {
    }

    public function show(): void
    {
        $image = <<<SPLASH
    ++                                        +++  
     ++++                                  ++++    
      +++                                  +++     
         ++                              ++        
           ++                          ++          
             ++                      ++            
               +                    ++             
                ++                ++               
                  ++            ++         +       
                    ++        ++         +++++     
  ++++++++++          +      ++        +++    ++   
+++         ++         ++  ++        +++       ++  
++          ++           ++         ++           ++
  ++       +           ++  ++        ++         ++ 
    +    ++           ++     +         ++     ++   
     ++++           ++        ++         ++ +++    
       +          ++            ++        +++      
                ++                ++               
               ++                   +              
             ++          +++         ++            
           ++          ++  +           ++          
         ++           ++    ++           ++        
        ++           ++      ++            +       
    ++++            ++        ++            ++++   
  +++++            ++          ++           +++++  
  ++++                                          +  
SPLASH;

        Console::clear();
        $terminalWidth = $this->settings->width;
        $terminalHeight = $this->settings->height;

        $imageRows = explode("\n", $image);
        $imageWidth = array_reduce($imageRows, function (?string $a, ?string $b): int {
            if (!$a) {
                return strlen($b ?? '');
            }

            if (!$b) {
                return strlen($a);
            }

            $lengthA = strlen($a);
            $lengthB = strlen($b);
            return ($lengthA > $lengthB) ? $lengthA : $lengthB;
        });
        $imageHeight = count($imageRows);

        $leftMargin = max(0, (($terminalWidth / 2) - ($imageWidth / 2)));
        $topMargin = max(0, (($terminalHeight / 2) - ($imageHeight / 2)));

        foreach ($imageRows as $index => $imageRow) {
            $this->cursor->moveTo((int)$leftMargin, (int)($topMargin + $index));
            $this->output->write($imageRow);
        }

        $duration = (int)(self::SPLASH_SCREEN_DURATION * 1000000);
        usleep($duration);

        Console::clear();
    }
}