using System;
using System.Collections.Generic;
using System.Linq;
using System.Windows;
using System.Windows.Controls;

namespace TuiAppHost;

/// <summary>
/// The stack kind: children one after the other, with a gap. Children with grow &gt; 0 first take their
/// natural size, then share what is left by weight (flex-grow with an automatic basis). Every child
/// fills the cross axis.
/// </summary>
public sealed class WeightedStackPanel : Panel
{
    public static readonly DependencyProperty GrowProperty = DependencyProperty.RegisterAttached(
        "Grow", typeof(int), typeof(WeightedStackPanel),
        new FrameworkPropertyMetadata(0, FrameworkPropertyMetadataOptions.AffectsParentMeasure | FrameworkPropertyMetadataOptions.AffectsParentArrange));

    public static readonly DependencyProperty HorizontalProperty = DependencyProperty.Register(
        nameof(Horizontal), typeof(bool), typeof(WeightedStackPanel),
        new FrameworkPropertyMetadata(false, FrameworkPropertyMetadataOptions.AffectsMeasure));

    public static readonly DependencyProperty GapProperty = DependencyProperty.Register(
        nameof(Gap), typeof(double), typeof(WeightedStackPanel),
        new FrameworkPropertyMetadata(0.0, FrameworkPropertyMetadataOptions.AffectsMeasure));

    public static int GetGrow(UIElement element) => (int)element.GetValue(GrowProperty);

    public static void SetGrow(UIElement element, int value) => element.SetValue(GrowProperty, value);

    public bool Horizontal
    {
        get => (bool)GetValue(HorizontalProperty);
        set => SetValue(HorizontalProperty, value);
    }

    public double Gap
    {
        get => (double)GetValue(GapProperty);
        set => SetValue(GapProperty, value);
    }

    protected override Size MeasureOverride(Size available)
    {
        var lengths = Lay(available, measure: true);
        var visible = Visible().ToList();
        var main = lengths.Sum() + Gaps(visible.Count);
        var cross = visible.Count == 0 ? 0 : visible.Max(child => Cross(child.DesiredSize));
        return Make(double.IsInfinity(Main(available)) ? main : Math.Min(main, Main(available)), cross);
    }

    protected override Size ArrangeOverride(Size final)
    {
        var lengths = Lay(final, measure: false);
        var visible = Visible().ToList();
        var position = 0.0;
        for (var i = 0; i < visible.Count; i++)
        {
            var child = visible[i];
            child.Arrange(Horizontal
                ? new Rect(position, 0, lengths[i], final.Height)
                : new Rect(0, position, final.Width, lengths[i]));
            position += lengths[i] + Gap;
        }

        foreach (UIElement child in InternalChildren)
        {
            if (child.Visibility == Visibility.Collapsed)
            {
                child.Arrange(new Rect(0, 0, 0, 0));
            }
        }

        return final;
    }

    /// <summary>The main-axis length of each visible child.</summary>
    private List<double> Lay(Size size, bool measure)
    {
        var visible = Visible().ToList();
        var crossSize = Cross(size);
        var lengths = new List<double>(visible.Count);
        foreach (var child in visible)
        {
            if (measure)
            {
                child.Measure(Make(double.PositiveInfinity, crossSize));
            }

            lengths.Add(Main(child.DesiredSize));
        }

        var total = Main(size);
        var weights = visible.Select(GetGrow).ToList();
        var weight = weights.Sum();
        if (weight > 0 && !double.IsInfinity(total))
        {
            var free = Math.Max(0, total - lengths.Sum() - Gaps(visible.Count));
            for (var i = 0; i < visible.Count; i++)
            {
                if (weights[i] > 0)
                {
                    lengths[i] += free * weights[i] / weight;
                    if (measure)
                    {
                        visible[i].Measure(Make(lengths[i], crossSize));
                    }
                }
            }
        }

        return lengths;
    }

    private IEnumerable<UIElement> Visible() => InternalChildren.Cast<UIElement>().Where(child => child.Visibility != Visibility.Collapsed);

    private double Gaps(int count) => count > 1 ? Gap * (count - 1) : 0;

    private double Main(Size size) => Horizontal ? size.Width : size.Height;

    private double Cross(Size size) => Horizontal ? size.Height : size.Width;

    private Size Make(double main, double cross) => Horizontal ? new Size(main, cross) : new Size(cross, main);
}
