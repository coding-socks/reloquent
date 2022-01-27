<?php

namespace CodingSocks\Reloquent\Tests;

use CodingSocks\Reloquent\Tests\Models\Movie;
use Illuminate\Database\Eloquent\Collection;

abstract class ModelTestTemplate extends TestCase
{
    /**
     * Define database migrations.
     *
     * @return void
     */
    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
    }

    public function testExists(): void
    {
        $movie = new Movie();
        $movie->title = "The Matrix";
        $movie->year = 1999;
        $movie->directors = ['Lana Wachowski', 'Lilly Wachowski'];
        $movie->save();

        $this->assertTrue(Movie::exists($movie->id));

        $this->assertFalse(Movie::exists('DOES_NOT_EXIST'));
    }

    public function testDelete(): void
    {
        $movie = new Movie();
        $movie->title = "The Matrix";
        $movie->year = 1999;
        $movie->directors = ['Lana Wachowski', 'Lilly Wachowski'];
        $movie->save();

        $this->assertTrue(Movie::exists($movie->id));

        $movie->delete();

        $this->assertFalse(Movie::exists($movie->id));
    }

    public function testFind(): void
    {
        $movie = new Movie();
        $movie->title = "The Matrix";
        $movie->year = 1999;
        $movie->directors = ['Lana Wachowski', 'Lilly Wachowski'];
        $movie->save();

        $found = Movie::find($movie->id);
        $this->assertNotNull($found);
        $this->assertEquals($movie->id, $found->id);
        $this->assertEquals($movie->title, $found->title);
        $this->assertEquals($movie->year, $found->year);
        $this->assertEquals($movie->directors, $found->directors);
    }

    public function testFindMissing(): void
    {
        $found = Movie::find('DOES_NOT_EXIST');
        $this->assertNotNull($found);
        $this->assertEquals('DOES_NOT_EXIST', $found->id);
        $this->assertNull($found->title);
        $this->assertNull($found->year);
        $this->assertNull($found->directors);
    }

    public function testFindMany(): void
    {
        $movie = new Movie();
        $movie->title = "WarGames";
        $movie->year = 1983;
        $movie->directors = ['John Badham'];
        $movie->save();

        $queryResult = Movie::findMany([$movie->id]);
        $this->assertNotNull($queryResult);
        $this->assertCount(1, $queryResult);
        $found = $queryResult[0];
        $this->assertEquals($movie->id, $found->id);
        $this->assertEquals($movie->title, $found->title);
        $this->assertEquals($movie->year, $found->year);
        $this->assertEquals($movie->directors, $found->directors);
    }

    public function testQuery(): void
    {
        $movie = new Movie();
        $movie->title = "Alice in Wonderland";
        $movie->year = 1951;
        $movie->directors = ['Clyde Geronimi', 'Wilfred Jackson', 'Hamilton Luske'];
        $movie->save();

        $queryResult = Movie::query()
            ->where('title', 'matches', ['Alice', 'Wonderland'])
            ->where('year', '<=', 2000)
            ->where('directors', 'contains', ['Clyde Geronimi', 'Hamilton Luske'])
            ->get();
        $this->assertNotNull($queryResult);
        $this->assertCount(1, $queryResult);
        $found = $queryResult->shift();
        $this->assertEquals($movie->id, $found->id);
        $this->assertEquals($movie->title, $found->title);
        $this->assertEquals($movie->year, $found->year);
        $this->assertEquals($movie->directors, $found->directors);
    }

    public function testLatest(): void
    {
        $warGames = new Movie();
        $warGames->title = "WarGames";
        $warGames->year = 1983;
        $warGames->save();

        usleep(1000);

        $aliceInWonderland = new Movie();
        $aliceInWonderland->title = "Alice in Wonderland";
        $aliceInWonderland->year = 1951;
        $aliceInWonderland->save();

        $latest = Movie::query()
            ->where('year', '<=', 2000)
            ->latest()
            ->first();
        $this->assertEquals("Alice in Wonderland", $latest->title);
    }

    public function testOldest(): void
    {
        $warGames = new Movie();
        $warGames->title = "WarGames";
        $warGames->year = 1983;
        $warGames->save();

        usleep(1000);

        $aliceInWonderland = new Movie();
        $aliceInWonderland->title = "Alice in Wonderland";
        $aliceInWonderland->year = 1951;
        $aliceInWonderland->save();

        $oldest = Movie::query()
            ->where('year', '<=', 2000)
            ->oldest()
            ->first();
        $this->assertEquals("WarGames", $oldest->title);
    }
}
