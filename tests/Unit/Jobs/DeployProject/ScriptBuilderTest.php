<?php

namespace REBELinBLUE\Deployer\Tests\Unit\Jobs\DeployProject;

use Exception;
use Illuminate\Support\Collection;
use Mockery as m;
use REBELinBLUE\Deployer\Command;
use REBELinBLUE\Deployer\Deployment;
use REBELinBLUE\Deployer\DeployStep;
use REBELinBLUE\Deployer\Jobs\DeployProject\ScriptBuilder;
use REBELinBLUE\Deployer\Project;
use REBELinBLUE\Deployer\Server;
use REBELinBLUE\Deployer\Services\Scripts\Parser as ScriptParser;
use REBELinBLUE\Deployer\Services\Scripts\Runner as Process;
use REBELinBLUE\Deployer\Tests\TestCase;
use REBELinBLUE\Deployer\Variable;

/**
 * @coversDefaultClass \REBELinBLUE\Deployer\Jobs\DeployProject\ScriptBuilder
 */
class ScriptBuilderTest extends TestCase
{
    /**
     * @var Process
     */
    private $process;

    /**
     * @var ScriptParser
     */
    private $parser;

    /**
     * @var Deployment
     */
    private $deployment;

    /**
     * @var DeployStep
     */
    private $step;

    /**
     * @var Server
     */
    private $server;

    /**
     * @var string
     */
    private $release_archive;

    /**
     * @var string
     */
    private $private_key;

    /**
     * @var Project
     */
    private $project;

    public function setUp()
    {
        parent::setUp();

        $deployment_id   = 12312;
        $release_archive = 'release.tar.gz';
        $private_key     = '/tmp/id_rsa.private.key';
        $clean_path      = '/var/www';
        $release_id      = 20170110155645;
        $branch          = 'master';
        $commit          = 'e94168a2cb070d1b3163b58fb052285d3ea9ba12';
        $short_commit    = 'e94168a';
        $committer_email = 'committer@example.com';
        $committer       = 'committer-name';
        $user            = 'root';

        $parser = m::mock(ScriptParser::class);

        $project = m::mock(Project::class);
        $project->shouldReceive('getAttribute')->with('include_dev')->andReturn(true);
        $project->shouldReceive('getAttribute')->with('builds_to_keep')->andReturn(5);

        $deployment = m::mock(Deployment::class);
        $deployment->shouldReceive('getAttribute')->with('project')->andReturn($project);
        $deployment->shouldReceive('getAttribute')->with('release_id')->andReturn($release_id);
        $deployment->shouldReceive('getAttribute')->with('id')->andReturn($deployment_id);
        $deployment->shouldReceive('getAttribute')->with('branch')->andReturn($branch);
        $deployment->shouldReceive('getAttribute')->with('id')->andReturn($deployment_id);
        $deployment->shouldReceive('getAttribute')->with('commit')->andReturn($commit);
        $deployment->shouldReceive('getAttribute')->with('short_commit')->andReturn($short_commit);
        $deployment->shouldReceive('getAttribute')->with('committer_email')->andReturn($committer_email);
        $deployment->shouldReceive('getAttribute')->with('committer')->andReturn($committer);

        $step = m::mock(DeployStep::class);

        $server = m::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('clean_path')->andReturn($clean_path);
        $server->shouldReceive('getAttribute')->with('user')->andReturn($user);

        $process = m::mock(Process::class);
        $process->shouldReceive('setServer')->with($server, $private_key, $user);

        $this->process         = $process;
        $this->parser          = $parser;
        $this->deployment      = $deployment;
        $this->server          = $server;
        $this->step            = $step;
        $this->project         = $project;
        $this->release_archive = $release_archive;
        $this->private_key     = $private_key;
    }

    /**
     * @dataProvider provideDeploySteps
     * @covers ::__construct
     * @covers ::setup
     * @covers ::buildScript
     * @covers ::getTokens
     * @covers ::getScriptForStep
     * @covers ::exports
     */
    public function testBuildScriptForDeployStep($stage, $script)
    {
        $this->step->shouldReceive('isCustom')->andReturn(false);
        $this->step->shouldReceive('getAttribute')->with('stage')->andReturn($stage);

        $this->project->shouldReceive('getAttribute')->with('variables')->andReturn(new Collection());

        $this->process->shouldReceive('setScript')->with($script, m::type('array'))->andReturnSelf();
        $this->process->shouldReceive('prependScript')->with('')->andReturnSelf();

        $this->deployment->shouldReceive('getAttribute')->with('user')->andReturnNull();
        $this->deployment->shouldReceive('getAttribute')->with('is_webhook')->andReturn(true);
        $this->deployment->shouldReceive('getAttribute')->with('source')->andReturnNull();

        $job = new ScriptBuilder($this->process, $this->parser);
        $job->setup($this->deployment, $this->step, $this->release_archive, $this->private_key)
            ->buildScript($this->server);
    }

    /**
     * @covers ::__construct
     * @covers ::setup
     * @covers ::buildScript
     * @covers ::getTokens
     * @covers ::getScriptForStep
     * @covers ::exports
     * @covers ::configurationFileCommands
     * @covers ::shareFileCommands
     */
    public function testBuildScriptForInstallStep()
    {
        $this->step->shouldReceive('isCustom')->andReturn(false);
        $this->step->shouldReceive('getAttribute')->with('stage')->andReturn(Command::DO_INSTALL);

        $this->project->shouldReceive('getAttribute')->with('variables')->andReturn(new Collection());
        $this->project->shouldReceive('getAttribute')->with('configFiles')->andReturn(new Collection());
        $this->project->shouldReceive('getAttribute')->with('sharedFiles')->andReturn(new Collection());

        $this->process->shouldReceive('setScript')
                      ->with('deploy.steps.InstallComposerDependencies', m::type('array'))
                      ->andReturnSelf();
        $this->process->shouldReceive('prependScript')->with('')->andReturnSelf();
        $this->process->shouldReceive('appendScript')->with('')->andReturnSelf();

        $this->deployment->shouldReceive('getAttribute')->with('user')->andReturnNull();
        $this->deployment->shouldReceive('getAttribute')->with('is_webhook')->andReturn(true);
        $this->deployment->shouldReceive('getAttribute')->with('source')->andReturnNull();

        $job = new ScriptBuilder($this->process, $this->parser);
        $job->setup($this->deployment, $this->step, $this->release_archive, $this->private_key)
            ->buildScript($this->server);
    }

    public function provideDeploySteps()
    {
        return $this->fixture('Jobs/DeployProject/ScriptBuilder')['steps'];
    }

    /**
     * @covers ::__construct
     * @covers ::setup
     * @covers ::buildScript
     * @covers ::getTokens
     * @covers ::getScriptForStep
     * @covers ::exports
     * @covers ::configurationFileCommands
     * @covers ::shareFileCommands
     */
    public function testBuildCustomScript()
    {
        $script = 'ls -la';
        $user   = 'deploy';

        $command = m::mock(Command::class);
        $command->shouldReceive('getAttribute')->with('user')->andReturn($user);
        $command->shouldReceive('getAttribute')->with('script')->andReturn($script);

        $this->step->shouldReceive('getAttribute')->with('command')->andReturn($command);

        $this->step->shouldReceive('isCustom')->andReturn(true);
        $this->step->shouldReceive('getAttribute')->with('stage')->andReturn(Command::AFTER_CLONE);

        $this->project->shouldReceive('getAttribute')->with('variables')->andReturn(new Collection());

        $this->process->shouldReceive('setScript') // FIXME: Clean up the first parameter?
                      ->with(m::type('string'), m::type('array'), Process::DIRECT_INPUT)
                      ->andReturnSelf();
        $this->process->shouldReceive('prependScript')->with('')->andReturnSelf();
        $this->process->shouldReceive('setServer')->with($this->server, $this->private_key, $user);

        $this->deployment->shouldReceive('getAttribute')->with('user')->andReturnNull();
        $this->deployment->shouldReceive('getAttribute')->with('is_webhook')->andReturn(true);
        $this->deployment->shouldReceive('getAttribute')->with('source')->andReturnNull();

        $job = new ScriptBuilder($this->process, $this->parser);
        $job->setup($this->deployment, $this->step, $this->release_archive, $this->private_key)
            ->buildScript($this->server);
    }

    /**
     * @dataProvider provideDeploySteps
     * @covers ::__construct
     * @covers ::setup
     * @covers ::buildScript
     * @covers ::getTokens
     * @covers ::getScriptForStep
     * @covers ::exports
     */
    public function testBuildScriptIncludesVariables()
    {
        $exports   = 'export VAR=value' . PHP_EOL . 'export FOO=BAR' . PHP_EOL;
        $variables = new Collection();

        $variable1        = new Variable();
        $variable1->name  = 'VAR';
        $variable1->value = 'value';
        $variables->push($variable1);

        $variable2        = new Variable();
        $variable2->name  = 'FOO';
        $variable2->value = 'BAR';
        $variables->push($variable2);

        $this->step->shouldReceive('isCustom')->andReturn(false);
        $this->step->shouldReceive('getAttribute')->with('stage')->andReturn(Command::DO_CLONE);

        $this->project->shouldReceive('getAttribute')->with('variables')->andReturn($variables);

        $this->process->shouldReceive('setScript')
                      ->with('deploy.steps.CreateNewRelease', m::type('array'))
                      ->andReturnSelf();

        $this->process->shouldReceive('prependScript')->with($exports)->andReturnSelf();

        $this->deployment->shouldReceive('getAttribute')->with('user')->andReturnNull();
        $this->deployment->shouldReceive('getAttribute')->with('is_webhook')->andReturn(true);
        $this->deployment->shouldReceive('getAttribute')->with('source')->andReturnNull();

        $job = new ScriptBuilder($this->process, $this->parser);
        $job->setup($this->deployment, $this->step, $this->release_archive, $this->private_key)
            ->buildScript($this->server);
    }

    /**
     * @covers ::__construct
     * @covers ::buildScript
     */
    public function testBuildScriptThrowsExceptionIsSetupNotCalled()
    {
        $this->expectException(Exception::class);

        $process = m::mock(Process::class);
        $parser  = m::mock(ScriptParser::class);
        $server  = m::mock(Server::class);

        $job = new ScriptBuilder($process, $parser);
        $job->buildScript($server);
    }
}