<?php

namespace test;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Yaml\Yaml;

class SwitchoverDbUsingProxySql extends Command
{
    protected function configure()
    {
        $this->setName("switchover");

        $this->addOption("m1", null, InputOption::VALUE_REQUIRED, "M1 connection string, looks like 127.0.0.1:3306");
        $this->addOption("m1-user", null, InputOption::VALUE_REQUIRED, "M1 username (you'll be prompted for the password)");
        $this->addOption("m1-password", null, InputOption::VALUE_OPTIONAL, "M1 password");

        $this->addOption("m2", null, InputOption::VALUE_REQUIRED, "M2 connection string, looks like 127.0.0.1:3306");
        $this->addOption("m2-user", null, InputOption::VALUE_REQUIRED, "M2 username (you'll be prompted for the password)");
        $this->addOption("m2-password", null, InputOption::VALUE_OPTIONAL, "M2 password");

        $this->addOption("proxysql", null, InputOption::VALUE_REQUIRED, "ProxySQL administrator connection string, looks like 127.0.0.1:6032");
        $this->addOption("proxysql-user", null, InputOption::VALUE_REQUIRED, "ProxySQL username");
        $this->addOption("proxysql-password", null, InputOption::VALUE_OPTIONAL, "ProxySQL password");

        $this->addOption("m1-proxysql", null, InputOption::VALUE_REQUIRED, "M1 connection string as seen from ProxySQL, defaults to the value of --m1");
        $this->addOption("m2-proxysql", null, InputOption::VALUE_REQUIRED, "M2 connection string as seen from ProxySQL, defaults to the value of --m2");

        $this->addOption("config", "c", InputOption::VALUE_OPTIONAL,
            "Optional YAML file with passwords, keys are m1, m2 and proxysql with values being passwords for corresponding users");

        $this->setDescription(<<<DESC
Switch over from M1 DB to M2 using ProxySQL

Prereqs.:
  - M1 and M2 are MySQL DBs (RDS)
  - There's currently M1 -> M2 replication going on
  - Whereas M1 is a current master, M2 is a read-write slave ready to accept new writes and overtake the master role
  - Both M1 and M2 are added to ProxySQL, M1 is ONLINE and M2 is currently OFFLINE_SOFT

DESC
        );
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        /** @var ConsoleOutputInterface $output */

        $requiredOptions = [
            "m1", "m1-user",
            "m2", "m2-user",
            "proxysql", "proxysql-user"
        ];

        foreach ($requiredOptions as $opt) {
            if ($input->getOption($opt) === null) {
                $output->writeln("<error>Missing required option --{$opt}</error>");
                exit(1);
            }
        }

        if ($input->getOption("config")) {
            if (!file_exists($input->getOption("config"))) {
                $output->writeln("<error>Couldn't find config file</error>");

                exit(1);
            }

            $config = Yaml::parse(file_get_contents($input->getOption("config")));
            $setOpt = function ($name, $value) use ($input) {
                if ($input->getOption($name) === null) {
                    $input->setOption($name, $value);
                }
            };

            foreach ($config as $key => $value) {
                switch ($key) {
                    case "m1-password":
                        $setOpt("m1-password", $value);
                        break;
                    case "m2-password":
                        $setOpt("m2-password", $value);
                        break;
                    case "proxysql-password":
                        $setOpt("proxysql-password", $value);
                        break;
                }
            }
        }

        foreach (["m1-password", "m2-password", "proxysql-password"] as $name) {
            if ($input->getOption($name) === null) {
                $ask = new QuestionHelper();
                $value = $ask->ask($input, $output, new Question("{$name}: "));

                $input->setOption($name, $value);
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ConsoleOutputInterface $output */

        $m1 = new \PDO("mysql:host={$this->parseConnectionString($input->getOption("m1"))["host"]};port={$this->parseConnectionString($input->getOption("m1"))["port"]}",
            $input->getOption("m1-user"),
            $input->getOption("m1-password"));
        $m1->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);

        $m2 = new \PDO("mysql:host={$this->parseConnectionString($input->getOption("m2"))["host"]};port={$this->parseConnectionString($input->getOption("m2"))["port"]}",
            $input->getOption("m2-user"),
            $input->getOption("m2-password"));
        $m2->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);

        $prx = new \PDO("mysql:host={$this->parseConnectionString($input->getOption("proxysql"))["host"]};port={$this->parseConnectionString($input->getOption("proxysql"))["port"]}",
            $input->getOption("proxysql-user"),
            $input->getOption("proxysql-password"));
        $prx->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);

        $currentStep = "pre-offline";

        system('stty cbreak');
        $lineUpSpecialCharacters = `tput cuu1` . `tput el`;
        $term = new Terminal();

        $clearScreen = function () use ($output, $lineUpSpecialCharacters, &$term) {
            $output->write(str_repeat($lineUpSpecialCharacters, $term->getHeight()));
        };
        $getKeystroke = function () {
            $r = [STDIN]; $w = NULL; $e = NULL;
            if (stream_select($r, $w, $e, 0)) {
                $pressed = ord(stream_get_contents(STDIN, 1));

                $map = [
                    79 => "O",
                    111 => "O",
                    115 => "S",
                    83 => "S",
                    66 => "B",
                    98 => "B"
                ];

                return $map[$pressed] ?? null;
            }

            return null;
        };

        $m1FromProxySql = $input->getOption("m1-proxysql") ? $this->parseConnectionString($input->getOption("m1-proxysql")) : $this->parseConnectionString($input->getOption("m1"));
        $m2FromProxySql = $input->getOption("m2-proxysql") ? $this->parseConnectionString($input->getOption("m2-proxysql")) : $this->parseConnectionString($input->getOption("m2"));

        $stmt = $prx->prepare("SELECT * FROM mysql_servers WHERE hostname = ? AND port = ? AND status='ONLINE'");
        $stmt->bindValue(1, $m1FromProxySql["host"]);
        $stmt->bindValue(2, $m1FromProxySql["port"]);
        assert($stmt->execute());
        $m1Servers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (count($m1Servers) > 1) {
            $output->writeln("<error>Error:</error> more than one M1 server found in ProxySQL: " . implode(", ", array_map(function ($s) { return "{$s["hostname"]}:{$s["port"]}"; }, $m1Servers)));
            return 1;
        }
        if (!$m1Servers) {
            $output->writeln("<error>Error:</error> no M1 servers found in ProxySQL");
            return 1;
        }

        $stmt = $prx->prepare("SELECT * FROM mysql_servers WHERE hostname = ? AND port = ? AND status='OFFLINE_SOFT'");
        $stmt->bindValue(1, $m2FromProxySql["host"]);
        $stmt->bindValue(2, $m2FromProxySql["port"]);
        assert($stmt->execute());
        $m2Servers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (count($m2Servers) > 1) {
            $output->writeln("<error>Error:</error> more than one M1 server found in ProxySQL: " . implode(", ", array_map(function ($s) { return "{$s["hostname"]}:{$s["port"]}"; }, $m2Servers)));
            return 1;
        }
        if (!$m1Servers) {
            $output->writeln("<error>Error:</error> no M2 servers found in ProxySQL");
            return 1;
        }

        $stmt = $prx->prepare("SELECT * FROM mysql_servers WHERE NOT ((hostname = ? AND port = ?) OR (hostname = ? AND port = ?)) AND status = 'ONLINE'");
        $stmt->bindValue(1, $m1FromProxySql["host"]);
        $stmt->bindValue(2, $m1FromProxySql["port"]);
        $stmt->bindValue(3, $m2FromProxySql["host"]);
        $stmt->bindValue(4, $m2FromProxySql["port"]);
        assert($stmt->execute());
        $otherServers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($otherServers) {
            $otherServersStr = implode(", ", array_map(function ($s) { return "{$s["hostname"]}:{$s["port"]}"; }, $otherServers));

            $ask = new QuestionHelper();
            $answer = $ask->ask($input, $output, new Question("<info>There are other servers configured ({$otherServersStr}), you sure that you want to continue? (type 'yes')</info>"));

            if ($answer !== "yes") {
                return 1;
            }
        }

        $rules = $prx->query("SELECT * FROM mysql_query_rules")->fetchAll(\PDO::FETCH_ASSOC);
        if ($rules) {
            $ask = new QuestionHelper();
            $answer = $ask->ask($input, $output, new Question("<info>There are active mysql_query_rules, it's best " .
                "that we continue without existing mysql_query_rules making ProxySQL route all queries according to " .
                "MySQL user's defaults (which should be to hostgroup=0). Would you like me to drop all existing query rules? (type 'yes' to agree)</info>"));

            if ($answer === "yes") {
                $prx->query("DELETE FROM mysql_query_rules");
                $output->writeln("> <info>DELETE FROM mysql_query_rules</info>");
                $prx->query("LOAD MYSQL QUERY RULES TO RUNTIME");
                $output->writeln("> <info>LOAD MYSQL QUERY RULES TO RUNTIME</info>");
            }
        }

        while (true) {

            $stmt = $m1->query("SELECT * FROM INFORMATION_SCHEMA.PROCESSLIST WHERE COMMAND!='Sleep' ORDER BY INFO");
            $processList = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $table = new Table($output);
            $table->setHeaders(["User", "Comm.", "Time", "Query" . str_repeat(" ", 95), "State"]);

            foreach ($processList as $proc) {
                $info = $proc["INFO"];

                $info = preg_replace("/^SELECT.+?FROM[ \t]+([A-z0-9_]+).+$/", "SELECT \\1", $info);
                $info = preg_replace("/^INSERT[ \t]+INTO[ \t]+([A-z0-9_]+).+$/", "INSERT \\1", $info);
                $info = preg_replace("/^UPDATE[ \t]+([A-z0-9_]+).+$/", "UPDATE \\1", $info);
                $info = substr($info, 0, 100);

                $table->addRow([
                    $proc["USER"],
                    $proc["COMMAND"],
                    $proc["TIME"],
                    $info,
                    substr($proc["STATE"], 0, 100)
                ]);
            }

            for ($i = $term->getHeight() - 9 - count($processList); $i > 0; $i--) {
                $table->addRow(["", "", "", "", ""]);
            }


            $slavePos = [];
            $slaveStatuses = $m2->query("SHOW SLAVE STATUS")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($slaveStatuses as $slaveStatus) {
                $slavePos[] = $slaveStatus["Exec_Master_Log_Pos"];
            }

            $masterPos = [];
            $masterStatuses = $m1->query("SHOW MASTER STATUS")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($masterStatuses as $masterStatus) {
                $masterPos[] = $masterStatus["Position"];
            }



            $keystroke = $getKeystroke();

            if ($keystroke === "O" && $currentStep === "pre-offline") {
                $currentStep = "offline-soft";

                $stmt = $prx->prepare("UPDATE mysql_servers SET status = 'OFFLINE_SOFT' WHERE hostname = ? AND port = ?");
                $stmt->bindValue(1, $m1FromProxySql["host"]);
                $stmt->bindValue(2, $m1FromProxySql["port"]);

                if (!$stmt->execute() || !$stmt->rowCount()) {
                    $stmt = $prx->prepare("UPDATE mysql_servers SET status = 'ONLINE' WHERE hostname = ? AND port = ?");
                    $stmt->bindValue(1, $m1FromProxySql["host"]);
                    $stmt->bindValue(2, $m1FromProxySql["port"]);

                    $output->writeln("<error>Error:</error> Couldn't UPDATE mysql_servers in ProxySQL");
                    return 1;
                }

                $prx->query("LOAD MYSQL SERVERS TO RUNTIME");
            } elseif ($keystroke === "S" && $currentStep === "offline-soft") {
                $currentStep = "migrated";

                $stmt = $prx->prepare("UPDATE mysql_servers SET status='ONLINE' WHERE hostname = ? AND port = ?");
                $stmt->bindValue(1, $m2FromProxySql["host"]);
                $stmt->bindValue(2, $m2FromProxySql["port"]);

                if (!$stmt->execute()) {
                    $output->writeln("<error>Error:</error> couldn't activate M2 - there's no active servers currently");
                    return 1;
                }

                $prx->query("LOAD MYSQL SERVERS TO RUNTIME");
            } elseif ($keystroke === "B" && $currentStep === "offline-soft") {
                $currentStep = "pre-offline";

                $stmt = $prx->prepare("UPDATE mysql_servers SET status='ONLINE' WHERE hostname = ? AND port = ?");
                $stmt->bindValue(1, $m1FromProxySql["host"]);
                $stmt->bindValue(2, $m1FromProxySql["port"]);

                if (!$stmt->execute()) {
                    $output->writeln("<error>Error:</error> couldn't turn M1 back online - there's no active servers currently");
                    return 1;
                }

                $prx->query("LOAD MYSQL SERVERS TO RUNTIME");
            }

            $clearScreen();

            $output->writeln("Current processes on M1");
            $table->render();

            if ($masterPos && $slavePos) {
                $output->writeln("Replication seems " . ($masterPos[0] === $slavePos[0] ? "<info>up to date</info>" : "<error>syncing   </error>")
                    . ": master at " . implode(", ", $masterPos) . "; slave at " . implode(", ", $slavePos));
            } else {
                $output->writeln("Replication doesn't seem to be configured");
            }


            $currentStatus = "";
            switch ($currentStep) {
                case "pre-offline":
                    $currentStatus = "<info>waiting</info> for a command to send M1 to OFFLINE_SOFT";
                    break;
                case "offline-soft":
                    $currentStatus = "<comment>OFFLINE_SOFT</comment>, waiting for a command (S) to remove M1 and add M2";
                    break;
                case "migrated":
                    $currentStatus = "<comment>ONLINE</comment> migrated to M2";
                    break;
            }
            $output->writeln("Currently: {$currentStatus}");

            $output->writeln("Press <comment>O</comment> (oh) to make M1 status=OFFLINE_SOFT to drain all running transactions on it, then <comment>S</comment> to remove M1 and add ONLINE M2, or <comment>B</comment> to make M1 back ONLINE");

            usleep(200000);
        }

        return 0;
    }

    private function parseConnectionString($dsn)
    {
        if (!preg_match("/^(.+):(\d+)$/", $dsn, $match)) {
            throw new \RuntimeException("Couldn't parse {$dsn}");
        }

        return [
            "host" => $match[1],
            "port" => $match[2]
        ];
    }
}
