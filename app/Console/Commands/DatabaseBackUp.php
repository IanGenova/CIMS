<?php



namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;



class DatabaseBackUp extends Command

{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'database:backup';



    /**
     * The console command description.
     *
     * @var string
     */

    protected $description = 'Command description';



    /**
     * Create a new command instance.
     *
     * @return void
     */

    public function __construct()

    {
        parent::__construct();
    }



    /**
     * Execute the console command.
     *
     * @return int
     */

    /**
     * Where the copies go: private storage, never storage/app/public, which
     * public/storage serves to anyone (DatabaseBackUpLocationTest).
     */
    public static function directory()
    {
        return storage_path('app/backup');
    }

    public function handle()
    {
        @mkdir(self::directory(), 0755, true);
        foreach (glob(self::directory() . '/*') as $filename) {
            @unlink(self::directory() . '/' . basename($filename));
        }

        $db_pass=env('DB_PASSWORD');
        $filename = "backup-" . Carbon::now()->format('Y-m-d') . ".sql"; 
        if($db_pass !='') {
            $command = "".env('DUMP_PATH')." --user=" . env('DB_USERNAME') . " --password='$db_pass'" ." --host=" . env('DB_HOST') . " " . env('DB_DATABASE') . "  > " . self::directory() . "/" . $filename;
        }else{
            $command = "".env('DUMP_PATH')." --user=" . env('DB_USERNAME') . " --password=$db_pass" ." --host=" . env('DB_HOST') . " " . env('DB_DATABASE') . "  > " . self::directory() . "/" . $filename;

        }
        $returnVar = NULL;
        $output = NULL;
        exec($command, $output, $returnVar);
    }

}