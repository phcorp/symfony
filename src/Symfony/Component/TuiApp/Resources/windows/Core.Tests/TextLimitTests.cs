using System.Text.Json.Nodes;
using Xunit;

namespace TuiAppHost.Core.Tests;

public class TextLimitTests
{
    [Fact]
    public void CountsCodePointsAsTheApplicationDoes()
    {
        Assert.Equal(0, TextLimit.CodePoints(""));
        Assert.Equal(3, TextLimit.CodePoints("été"));
        // Two UTF-16 units each, one code point each, as mb_strlen counts them
        Assert.Equal(2, TextLimit.CodePoints("😀😀"));
        Assert.Equal(4, "😀😀".Length);
    }

    [Fact]
    public void TypingAndPastesStopAtTheLimit()
    {
        Assert.Equal("ab", TextLimit.Fit("abc", 3, 0, "abcdef", 5));
        Assert.Equal("", TextLimit.Fit("abcde", 5, 0, "x", 5));
        // What is selected is replaced: its room counts
        Assert.Equal("xyz", TextLimit.Fit("abcde", 1, 3, "xyzw", 5));
        // Never half a pair: 4 code points left, the fifth is cut whole
        Assert.Equal("x😀😀y", TextLimit.Fit("a", 1, 0, "x😀😀y😀", 5));
        Assert.Equal("😀", TextLimit.Fit("😀😀😀", 6, 0, "😀😀", 4));
    }

    [Fact]
    public void AValuePastTheLimitMayBeEditedWithoutGettingLonger()
    {
        // 7 code points, limit 5: what replaces a selection may be as long as it
        Assert.Equal("xy", TextLimit.Fit("abcdefg", 0, 2, "xyz", 5));
        Assert.Equal("x", TextLimit.Fit("abcdefg", 3, 1, "x", 5));
        Assert.Equal("", TextLimit.Fit("abcdefg", 7, 0, "x", 5));
    }

    [Fact]
    public void NoLimitLetsEverythingIn()
    {
        Assert.Equal("abcdef", TextLimit.Fit("abc", 3, 0, "abcdef", null));
    }

    [Fact]
    public void TheLimitComesFromTheNodeAndAnUpdateLiftsIt()
    {
        var tree = new NodeTree();
        tree.Apply(new PatchOp[]
        {
            new CreateOp(1, "stack", null, 0, new JsonObject()),
            new CreateOp(2, "textfield", 1, 0, new JsonObject { ["value"] = "", ["maxLength"] = 3 }),
            new CreateOp(3, "textarea", 1, 1, new JsonObject { ["value"] = "" }),
        });
        Assert.Equal(3, TextLimit.Of(tree.Find(2)!));
        Assert.Null(TextLimit.Of(tree.Find(3)!));

        tree.Apply(new PatchOp[] { new UpdateOp(2, new JsonObject { ["maxLength"] = null }), new UpdateOp(3, new JsonObject { ["maxLength"] = 5 }) });
        Assert.Null(TextLimit.Of(tree.Find(2)!));
        Assert.Equal(5, TextLimit.Of(tree.Find(3)!));
    }

    [Fact]
    public void AnEditTheControlMadeIsFoundAndCut()
    {
        Assert.Equal((3, 0, "xyz"), TextLimit.Splice("abc", "abcxyz"));
        Assert.Equal((1, 1, "XY"), TextLimit.Splice("abc", "aXYc"));
        Assert.Equal((0, 3, ""), TextLimit.Splice("abc", ""));
        // Never half a pair: the two emoji share their high surrogate
        Assert.Equal((1, 2, "😃"), TextLimit.Splice("a😀b", "a😃b"));
        // An input method's commit, already in the field: 29 of 30 taken, 日本 cut to 日
        var before = "Ada" + new string('1', 26);
        Assert.Equal(before + "日", TextLimit.Keep(before, before + "日本", 30, out var caret));
        Assert.Equal(30, caret);
        Assert.Equal(before + "日本", TextLimit.Keep(before, before + "日本", 31, out _));
        // A password field's paste over its selection, which it does not tell
        Assert.Equal("s3cret01", TextLimit.Keep("s3cret", "s3cret0123456789", 8, out caret));
        Assert.Equal(8, caret);
        // Past the limit (6 of 4): no longer than it was
        Assert.Equal("s3XYZ", TextLimit.Keep("s3cret", "s3XYZ", 4, out _));
        Assert.Equal("s3cret", TextLimit.Keep("s3cret", "s3cretX", 4, out caret));
        Assert.Equal(6, caret);
        Assert.Equal("abc", TextLimit.Keep("abc", "abcd", 3, out _));
        Assert.Equal("ab", TextLimit.Keep("abc", "ab", 1, out _));
    }

    [Fact]
    public void RepeatedTextIsCutWhereTheEditWas()
    {
        // xy made xyxy: from its common ends alone, xy inserted at the end; maxLength 3 keeps its x
        Assert.Equal("xyx", TextLimit.Keep("xy", "xyxy", 3, out var caret));
        Assert.Equal(3, caret);
        // The caret after the edit says where it was: yx inserted after the first x, its y kept
        Assert.Equal((1, 0, "yx"), TextLimit.Splice("xy", "xyxy", 3));
        Assert.Equal("xyy", TextLimit.Keep("xy", "xyxy", 3, null, 3, out caret));
        Assert.Equal(2, caret);
        // At the start: xy before all of it, its x kept
        Assert.Equal("xxy", TextLimit.Keep("xy", "xyxy", 2, null, 3, out caret));
        Assert.Equal(1, caret);
        Assert.Equal("xyx", TextLimit.Keep("xy", "xyxy", 4, null, 3, out caret));
        Assert.Equal(3, caret);
        // The commit's text, when known, says where the edit starts too: aa over the selected a of aab
        Assert.Equal((0, 1, "aa"), TextLimit.Splice("aab", "aaab", 2, "aa"));
        Assert.Equal((1, 0, "a"), TextLimit.Splice("aab", "aaab", 2));
        Assert.Equal("aab", TextLimit.Keep("aab", "aaab", 2, "aa", 3, out caret));
        Assert.Equal(1, caret);
        // A commit of 日本 after 日本 with room for one: the first 日 stays, 本 of the commit goes
        Assert.Equal("日本日", TextLimit.Keep("日本", "日本日本", 4, "日本", 3, out caret));
        Assert.Equal(3, caret);
    }

    [Fact]
    public void AnEndThatDoesNotFitTheTextsFallsBackToTheirCommonEnds()
    {
        // Past the end, before the start, or what follows it is not what followed the edit
        Assert.Equal(TextLimit.Splice("abc", "abcxyz"), TextLimit.Splice("abc", "abcxyz", 9));
        Assert.Equal(TextLimit.Splice("abc", "abcxyz"), TextLimit.Splice("abc", "abcxyz", -1));
        Assert.Equal(TextLimit.Splice("abc", "aXYc"), TextLimit.Splice("abc", "aXYc", 2));
        // A commit's text that is not before the end is a hint ignored
        Assert.Equal((3, 0, "xyz"), TextLimit.Splice("abc", "abcxyz", 6, "nope"));
        // Never half a pair, at either end
        Assert.Equal((1, 2, "😃"), TextLimit.Splice("a😀b", "a😃b", 3));
        Assert.Equal(TextLimit.Splice("a😀", "a😀😀"), TextLimit.Splice("a😀", "a😀😀", 4));
    }
}
